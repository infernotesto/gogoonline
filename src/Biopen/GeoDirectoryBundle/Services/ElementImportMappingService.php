<?php

namespace Biopen\GeoDirectoryBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Biopen\GeoDirectoryBundle\Document\Element;
use Biopen\GeoDirectoryBundle\Document\ElementStatus;
use Biopen\GeoDirectoryBundle\Document\ModerationState;
use Biopen\GeoDirectoryBundle\Document\Coordinates;
use Biopen\GeoDirectoryBundle\Document\Option;
use Biopen\GeoDirectoryBundle\Document\OptionValue;
use Biopen\GeoDirectoryBundle\Document\UserInteractionContribution;
use Biopen\GeoDirectoryBundle\Document\PostalAddress;
use Biopen\GeoDirectoryBundle\Document\ElementUrl;
use Biopen\GeoDirectoryBundle\Document\ElementImage;
use Biopen\GeoDirectoryBundle\Document\ImportState;
use Biopen\CoreBundle\Document\GoGoLogImport;
use Biopen\CoreBundle\Document\GoGoLogLevel;

class ElementImportMappingService
{
  protected $import;
  protected $em;
  protected $coreFields = ['id', 'name', 'categories', 'streetAddress', 'addressLocality', 'postalCode', 'addressCountry', 'latitude', 'longitude', 'images', 'owner', 'source'];

  public function __construct(DocumentManager $documentManager)
  {
    $this->em = $documentManager;
  }

  public function transform($data, $import)
  {
    $this->import = $import;
    $this->collectOntology($data, $import);
    $data = $this->mapOntology($data);
    // remove empty row, i.e. without name
    $data = array_filter($data, function($row) { return array_key_exists('name', $row); });
    $data = $this->addMissingFieldsToData($data);
    dump($data);
    $this->collectTaxonomy($data, $import);
    $data = $this->mapTaxonomy($data);
    dump($data);
    $this->em->persist($import);
    $this->em->flush();
    return $data;
  }

  public function collectOntology($data, $import)
  {
    $ontologyMapping = $import->getOntologyMapping();

    foreach($data as $row)
    {
      foreach ($row as $key => $value) {
        if (!array_key_exists($key, $ontologyMapping)) {
          $value = in_array($key, $this->coreFields) ? $key : "";
          $ontologyMapping[$key] = $value;
        }
      }
    }
    $import->setOntologyMapping($ontologyMapping);
  }

  public function collectTaxonomy($data, $import)
  {
    $taxonomyMapping = $import->getTaxonomyMapping();
    $this->createOptionsMappingTable();

    foreach($data as $row)
    {
      $taxonomy = $row['categories'];
      $taxonomy = is_array($taxonomy) ? $taxonomy : explode(',', $taxonomy);
      foreach($taxonomy as $category) {
        if ($category && !array_key_exists($category, $taxonomyMapping)) {
          $categorySlug = $this->slugify($category);
          $value = array_key_exists($categorySlug, $this->mappingTableIds) ? $this->mappingTableIds[$categorySlug]['id'] : '';
          $taxonomyMapping[$category] = $value;
        }
      }
    }
    $import->setTaxonomyMapping($taxonomyMapping);
  }

  private function mapOntology($data)
  {
    $mapping = $this->import->getOntologyMapping();

    foreach ($data as $key => $row) {
      foreach ($mapping as $search => $replace) {
        if ($replace == '/' || $replace == '') {
          unset($data[$key][$search]);
        }
        else if (isset($row[$search]) && !isset($row[$replace])) {
          $data[$key][$replace] = $data[$key][$search];
          unset($data[$key][$search]);
        }
      }
    }
    return $data;
  }

  private function addMissingFieldsToData($data)
  {
    foreach ($data as $key => $row) {
      $missingFields = array_diff($this->coreFields, array_keys($row));
      foreach ($missingFields as $missingField) {
        $data[$key][$missingField] = "";
      }
    }
    return $data;
  }

  private function mapTaxonomy($data)
  {
    $mapping = $this->import->getTaxonomyMapping();
    foreach ($data as $key => $row)
    {
      if (is_string($row['categories'])) $row['categories'] = explode(',', $row['categories']);
      $data[$key]['categories'] = array_map(function($el) use ($mapping) {
        return array_key_exists($el, $mapping) ? $mapping[$el] : '';
      }, $row['categories']);
    }
    return $data;
  }

  private function createOptionsMappingTable($options = null)
  {
    if ($options === null) $options = $this->em->getRepository('BiopenGeoDirectoryBundle:Option')->findAll();

    foreach($options as $option)
    {
      $ids = [
        'id' => $option->getId(),
        'idAndParentsId' => $option->getIdAndParentOptionIds()
      ];
      $this->mappingTableIds[$this->slugify($option->getNameWithParent())] = $ids;
      $this->mappingTableIds[$this->slugify($option->getName())] = $ids;
      $this->mappingTableIds[strval($option->getId())] = $ids;
      if ($option->getCustomId()) $this->mappingTableIds[$this->slugify($option->getCustomId())] = $ids;
    }
  }

  public function automapTaxonomy()
  {
    foreach($options as $optionName)
    {
      if ($optionName)
      {
        $optionNameSlug = $this->slugify($optionName);
        $optionExists = array_key_exists($optionNameSlug, $this->mappingTableIds);

        // create option if does not exist
        if (!$optionExists && $this->createMissingOptions) { $this->createOption($optionName); $optionExists = true; }

        if ($optionExists)
          // we add option id and parent options if not already added (because import works only with the lower level of options)
          foreach ($this->mappingTableIds[$optionNameSlug]['idAndParentsId'] as $key => $optionId)
            if (!in_array($optionId, $optionsIdAdded)) $optionsIdAdded[] = $this->addOptionValue($element, $optionId);
      }
    }
  }

  private function createOption($name)
  {
    $option = new Option();
    $option->setName($name);
    $parent = $this->em->getRepository('BiopenGeoDirectoryBundle:Category')->find($this->parentCategoryIdToCreateMissingOptions);
    $option->setParent($parent);
    $option->setUseIconForMarker(false);
    $option->setUseColorForMarker(false);
    $this->em->persist($option);
    $this->createOptionsMappingTable([$option]);
  }

  private function slugify($text)
  {
    if (!is_string($text)) return;
    // replace non letter or digits by -
    $text = str_replace('é', 'e', $text);
    $text = str_replace('è', 'e', $text);
    $text = str_replace('ê', 'e', $text);
    $text = str_replace('ô', 'o', $text);
    $text = str_replace('ç', 'c', $text);
    $text = str_replace('à', 'a', $text);
    $text = str_replace('â', 'a', $text);
    $text = str_replace('î', 'i', $text);
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);

    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text); // transliterate
    $text = preg_replace('~[^-\w]+~', '', $text); // remove unwanted characters
    $text = trim($text, '-'); // trim
    $text = rtrim($text, 's'); // remove final "s" for plural
    $text = preg_replace('~-+~', '-', $text); // remove duplicate -
    $text = strtolower($text); // lowercase

    if (empty($text)) return '';
    return $text;
  }
}