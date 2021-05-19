<?php

namespace App\Controller\Admin;

use App\Document\ImportState;
use App\Document\Option;
use App\Document\Category;
use App\Services\AsyncService;
use App\Services\ElementImportService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Sonata\AdminBundle\Controller\CRUDController as Controller;
use Symfony\Component\HttpFoundation\Request;

class ImportAdminController extends Controller
{
    public function collectAction(ElementImportService $importService)
    {
        $object = $this->admin->getSubject();
        $result = $importService->collectData($object);
        $count = $result === null ? null : count($result);
        $showUrl = $this->admin->generateUrl('showData', ['id' => $object->getId()]);
        $anchor = '';
        if ($result === null) {
            $msg = "Un problème semble avoir lieu pendant la lecture des données.";
            if ($object->getSourceType() == 'csv') $msg .= "Vérifiez que les colonnes sont bien <b>séparées avec des virgules</b> (et non pas avec des point virgules ou des espaces) : <a href='https://help.libreoffice.org/Calc/Importing_and_Exporting_CSV_Files/fr'>Cliquez ici pour savoir comment faire</a>. Vérifiez aussi que <b>l'encodage soit en UTF-8</b>.";
            if ($object->getSourceType() == 'json') "Vérifiez que le <b>tableau de donnée soit bien à la racine du JSON</b>. Si ce n'est pas le cas, utilisez l'onglet 'Modifier les données en exécutant du code'";
            $this->addFlash('sonata_flash_error', $msg);
        } elseif (!in_array('name', $object->getMappedProperties())) {
            $this->addFlash('sonata_flash_info', 'Merci de remplir le tableau de correspondance des champs. Renseignez au moins le Titre de la fiche');
            $anchor = '#tab_3';
        } elseif ($count == 0) {
            $this->addFlash('sonata_flash_error', 'Erreur pendant le chargement des données, le résultat est vide');
        } elseif ($count > 0) {
            $this->addFlash('sonata_flash_success', "<b>$count éléments ont été lus avec succès.</b></br>Voici le résultat obtenu pour le premier élément à importer :<pre>".print_r(reset($result), true).'</pre>'."<a href='$showUrl'>Voir toutes les données</a>");
            $anchor = '#tab_3';
        }
        $url = $this->admin->generateUrl('edit', ['id' => $object->getId()]) . $anchor;

        return $this->redirect($url);
    }

    public function showDataAction(ElementImportService $importService)
    {
        $object = $this->admin->getSubject();
        $result = $importService->collectData($object);

        $dataDisplay = print_r($result, true);
        $url = $this->admin->generateUrl('edit', ['id' => $object->getId()]);

        return $this->render('admin/pages/import/show-data.html.twig', [
          'dataDisplay' => $dataDisplay,
          'redirectUrl' => $url,
          'import' => $object,
        ]);
    }

    public function refreshAction(Request $request, DocumentManager $dm, ElementImportService $importService,
                                  AsyncService $asyncService)
    {
        $object = $this->admin->getSubject();

        if (!in_array('name', $object->getMappedProperties())) {
            $this->addFlash('sonata_flash_error', "Avant d'importer les données, vous devez d'abords remplir le tableau de correspondance des champs. Renseignez au moins le Titre de la fiche");
            $url = $this->admin->generateUrl('edit', ['id' => $object->getId()]);

            return $this->redirect($url);
        }

        $object->setCurrState(ImportState::Started);
        $object->setCurrMessage('En attente...');
        $dm->persist($object);
        $dm->flush();

        if ($request->get('direct')) {
            $importService->startImport($object);
        } else {
            $asyncService->callCommand('app:elements:importSource', [$object->getId(), $manuallystarted = true]);
        }

        $redirectionUrl = $this->admin->generateUrl('edit', ['id' => $object->getId()]);
        $stateUrl = $this->generateUrl('gogo_import_state', ['id' => $object->getId()]);

        return $this->render('admin/pages/import/import-progress.html.twig', [
          'import' => $object,
          'redirectUrl' => $redirectionUrl,
          'redirectListUrl' => $redirectionUrl = $this->admin->generateUrl('list'),
          'stateUrl' => $stateUrl,
        ]);
    }

    /**
     * Overite Sonata CRud Controller.
     */
    public function editAction($id = null)
    {
        $request = $this->getRequest();
        $id = $request->get($this->admin->getIdParameter());
        $object = $this->admin->getObject($id);

        if (!$object) {
            throw $this->createNotFoundException(sprintf('unable to find the object with id : %s', $id));
        }
        $dm = $this->container->get('doctrine_mongodb')->getManager();
        $config = $dm->get('Configuration')->findConfiguration();
        if ($config->getDuplicates()->getDetectAfterImport() && 
            $config->getDuplicates()->getAutomaticMergeIfPerfectMatch())
            $object->warnUserThatDuplicatesWillBeDetectedAndAutoMerged = true;

        $this->admin->checkAccess('edit', $object);
        $this->admin->setSubject($object);

        $oldUpdatedAt = $object->getMainConfigUpdatedAt();

        $form = $this->admin->getForm();
        $form->setData($object);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            //TODO: remove this check for 4.0
            if (method_exists($this->admin, 'preValidate')) {
                $this->admin->preValidate($object);
            }
            $isFormValid = $form->isValid();

            // persist if the form was valid and if in preview mode the preview was approved
            if ($isFormValid) {
                try {                    
                    $object->setSourceType($request->get('sourceType'));
                    
                    $ontology = $request->get('ontology');
                    // Fix ontology mapping for elements fields with reverse value      
                    if ($ontology) {                                         
                        
                        foreach($config->getElementFormFields() as $field) {
                            if ($field->type === 'elements'
                               && in_array($field->name, array_values($ontology))
                               && isset($field->reversedBy)
                               && in_array($field->reversedBy, array_values($ontology))) {
                                $this->addFlash('sonata_flash_info', "Les champs $field->name et $field->reversedBy étant liées entre eux, il n'est pas possible de les importer les deux en même temps. Seul le champ $field->name est conservé pour l'import, le champ $field->reversedBy sera automatiquement ajusté à la fin de l'import");
                                $key = array_search($field->reversedBy, $ontology);
                                $ontology[$key] = '/';
                            }
                        }
                    }                    
                    $object->setOntologyMapping($ontology);
                    $currentTaxonomyMapping = $object->getTaxonomyMapping();

                    // Taxonomy Mapping
                    if ($request->get('taxonomy')) {   
                        $createdParent = [];                     
                        $newTaxonomyMapping = $request->get('taxonomy');
                        $categoriesCreated = [];
                        foreach($newTaxonomyMapping as $originName => &$mappedCategories) {
                            $mappedCategories = explode(',', $mappedCategories[0]);
                            foreach($mappedCategories as $key => $category) {
                                // Create categories filled by user
                                if (startsWith($category, '@create:')) {
                                    $category = str_replace('@create:', '', $category);
                                    $categoryId = strtolower($category);
                                    if (array_key_exists($categoryId, $categoriesCreated)) {
                                        $mappedCategories[$key] = $categoriesCreated[$categoryId];
                                    } else {
                                        $fieldName = $currentTaxonomyMapping[$originName]['fieldName'];
                                        if (array_key_exists($fieldName, $createdParent))
                                            $parent = $createdParent[$fieldName];
                                        else
                                            $parent = $dm->get('Category')->findOneByCustomId($fieldName);
                                        if (!$parent) {
                                            $parent = new Category();
                                            $parent->setCustomId($fieldName);
                                            $parent->setPickingOptionText("une catégorie");
                                            $parent->setName($fieldName);
                                            $createdParent[$fieldName] = $parent;
                                        }
                                        $newCat = new Option();
                                        $newCat->setCustomId($categoryId);
                                        if ($object->getSourceType() == 'osm')
                                            $newCat->setOsmTag($fieldName, $categoryId);
                                        $newCat->setName($category);
                                        $newCat->setParent($parent);
                                        $dm->persist($newCat);
                                        $categoriesCreated[$categoryId] = $newCat->getId();
                                        $mappedCategories[$key] = $newCat->getId();
                                    }                                    
                                }
                            }
                        }  
                        unset($mappedCategories);                      
                    } else {
                        $newTaxonomyMapping = null;
                    }
                    $object->setTaxonomyMapping($newTaxonomyMapping);

                    // update option OSM tags of OptionstoAddToEachElement
                    // For example if the Osm query is "get OSM node with tag amenity=restaurant
                    // And if we decide to add a category to all those OSM node, then it means
                    // that this category reflect the OSM tag "amenity=restaurant"
                    if ($object->getSourceType() == 'osm' && count($object->getOsmQueries()) == 1) {
                        foreach($object->getOptionsToAddToEachElement() as $option) {
                            foreach($object->getOsmQueries()[0] as $condition) {
                                if (in_array($condition->operator, ['='])) {
                                    $option->setOsmTag($condition->key, $condition->value);
                                }
                            }                            
                        }
                    }

                    $object->setNewOntologyToMap(false);
                    $object->setNewTaxonomyToMap(false);
                    
                    // check manually for taxonomy change
                    if ($object->getTaxonomyMapping() != $currentTaxonomyMapping) {
                        $object->setMainConfigUpdatedAt(time());
                    }

                    $object = $this->admin->update($object);
                    // auto collect data if the import config have changed
                    if ($request->get('import')) {
                        $url = $this->admin->generateUrl('refresh', ['id' => $object->getId()]);
                    } elseif ($request->get('clear-elements')) {
                        $url = $this->admin->generateUrl('edit', ['id' => $object->getId()]);
                        $dm->query('Element')->field('source')->references($object)->batchRemove();
                        $this->addFlash('sonata_flash_success', "Les éléments liés à cet import ont été effacés");
                    } elseif ($request->get('collect') || $oldUpdatedAt != $object->getMainConfigUpdatedAt()) {
                        $url = $this->admin->generateUrl('collect', ['id' => $object->getId()]);
                    } else {
                        $url = $this->admin->generateUrl('edit', ['id' => $object->getId()]);
                        $this->addFlash('sonata_flash_success', $this->trans(
                            'flash_edit_success',
                            ['%name%' => $this->escapeHtml($this->admin->toString($object))],
                            'SonataAdminBundle' )
                        );
                    }
                    return $this->redirect($url);
                } catch (\Sonata\AdminBundle\Exception\ModelManagerException $e) {
                    $this->handleModelManagerException($e);
                    $isFormValid = false;
                } catch (\Sonata\AdminBundle\Exception\LockException $e) {
                    $this->addFlash('sonata_flash_error', $this->trans('flash_lock_error', [
                        '%name%' => $this->escapeHtml($this->admin->toString($object)),
                        '%link_start%' => '<a href="'.$this->admin->generateObjectUrl('edit', $object).'">',
                        '%link_end%' => '</a>',
                      ], 'SonataAdminBundle'));
                }
            }

            // show an error message if the form failed validation
            if (!$isFormValid) {
                if (!$this->isXmlHttpRequest()) {
                    $this->addFlash(
                        'sonata_flash_error',
                        $this->trans(
                          'flash_edit_error',
                          ['%name%' => $this->escapeHtml($this->admin->toString($object))],
                          'SonataAdminBundle'
                        )
                      );
                }
            }
        }

        $view = $form->createView();
        // set the theme for the current Admin Form
        $this->get('twig')->getRuntime(\Symfony\Component\Form\FormRenderer::class)
             ->setTheme($view, $this->admin->getFormTheme());

        return $this->render($this->admin->getTemplate('edit'), [
          'action' => 'edit',
          'form' => $view,
          'object' => $object,
        ], null);
    }

    /**
     * Overwrite Sonata CRud Controller.
     */
    public function createAction()
    {
        $request = $this->getRequest();
        // the key used to lookup the template
        $templateKey = 'edit';
        $this->admin->checkAccess('create');
        $object = $this->admin->getNewInstance();

        $this->admin->setSubject($object);

        $form = $this->admin->getForm();
        $form->setData($object);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $isFormValid = $form->isValid();

            // persist if the form was valid and if in preview mode the preview was approved
            if ($isFormValid && (!$this->isInPreviewMode($request) || $this->isPreviewApproved($request))) {
                try {
                    $object->setSourceType($request->get('sourceType')); // CUSTOM                   
                    $object = $this->admin->create($object);
                    // CUSTOM
                    $url = $this->admin->generateUrl('collect', ['id' => $object->getId()]);
                    return $this->redirect($url);
                } catch (\Sonata\AdminBundle\Exception\ModelManagerException $e) {
                    $this->handleModelManagerException($e);
                    $isFormValid = false;
                }
            }

            // show an error message if the form failed validation
            if (!$isFormValid) {
                if (!$this->isXmlHttpRequest()) {
                    $this->addFlash(
                      'sonata_flash_error',
                      $this->trans(
                          'flash_create_error',
                          ['%name%' => $this->escapeHtml($this->admin->toString($object))],
                          'SonataAdminBundle'
                      )
                  );
                }
            } elseif ($this->isPreviewRequested()) {
                // pick the preview template if the form was valid and preview was requested
                $templateKey = 'preview';
                $this->admin->getShow();
            }
        }

        $view = $form->createView();

        // set the theme for the current Admin Form
        $this->get('twig')->getRuntime(\Symfony\Component\Form\FormRenderer::class)
             ->setTheme($view, $this->admin->getFormTheme());

        return $this->render($this->admin->getTemplate($templateKey), [
          'action' => 'create',
          'form' => $view,
          'object' => $object,
        ], null);
    }
}
