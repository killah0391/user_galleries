<?php

// namespace Drupal\user_galleries\Form;

// use Drupal\Core\Form\FormBase;
// use Drupal\Core\Form\FormStateInterface;
// use Drupal\Core\Entity\EntityTypeManagerInterface;
// use Drupal\file\Entity\File;
// use Symfony\Component\DependencyInjection\ContainerInterface;

// /**
//  * Provides a form for cleaning up orphaned image references in galleries.
//  */
// class GalleryCleanupForm extends FormBase
// {

//   protected $entityTypeManager;

//   public function __construct(EntityTypeManagerInterface $entity_type_manager)
//   {
//     $this->entityTypeManager = $entity_type_manager;
//   }

//   public static function create(ContainerInterface $container)
//   {
//     return new static(
//       $container->get('entity_type.manager')
//     );
//   }

//   public function getFormId()
//   {
//     return 'user_galleries_cleanup_form';
//   }

//   public function buildForm(array $form, FormStateInterface $form_state)
//   {
//     $form['description'] = [
//       '#markup' => $this->t('This form allows administrators to scan galleries for references to images (file entities) that no longer exist in the database (i.e., they have been permanently deleted). You can then choose to remove these orphaned references from the galleries.<br><strong>Warning:</strong> This action is not reversible for the gallery entity references. Ensure files are truly gone and not just temporarily unavailable.'),
//     ];

//     $gallery_storage = $this->entityTypeManager->getStorage('gallery');
//     $galleries = $gallery_storage->loadMultiple();
//     $found_orphans = FALSE;

//     $form['galleries_to_cleanup'] = [
//       '#type' => 'checkboxes',
//       '#title' => $this->t('Galleries with Orphaned Image References'),
//       '#options' => [],
//     ];

//     $details = [];

//     foreach ($galleries as $gallery) {
//       $orphaned_fids_in_gallery = [];
//       $image_items = $gallery->get('images')->getValue();
//       $gallery_label = $gallery->label() . ' (ID: ' . $gallery->id() . ', Owner: ' . $gallery->getOwner()->getDisplayName() . ')';

//       foreach ($image_items as $item) {
//         if (empty($item['target_id'])) {
//           continue;
//         }
//         $fid = $item['target_id'];
//         // Check if the file entity exists.
//         $file_entity = File::load($fid);
//         if (!$file_entity) {
//           $orphaned_fids_in_gallery[] = $fid;
//           $found_orphans = TRUE;
//         }
//       }

//       if (!empty($orphaned_fids_in_gallery)) {
//         $form['galleries_to_cleanup']['#options'][$gallery->id()] = $gallery_label;
//         $details[$gallery->id()] = $this->t('Orphaned FIDs: @fids', ['@fids' => implode(', ', $orphaned_fids_in_gallery)]);
//       }
//     }

//     if ($found_orphans) {
//       $form['orphaned_details'] = [
//         '#type' => 'fieldset',
//         '#title' => $this->t('Details of Orphaned References'),
//         '#collapsible' => TRUE,
//         '#collapsed' => FALSE, // Show details by default
//       ];
//       foreach ($form['galleries_to_cleanup']['#options'] as $gallery_id => $gallery_label_option) {
//         if (isset($details[$gallery_id])) {
//           $form['orphaned_details'][$gallery_id] = [
//             '#type' => 'item',
//             '#title' => $gallery_label_option,
//             '#markup' => $details[$gallery_id],
//           ];
//         }
//       }
//       $form['actions']['#type'] = 'actions';
//       $form['actions']['submit'] = [
//         '#type' => 'submit',
//         '#value' => $this->t('Clean Selected Galleries'),
//         '#button_type' => 'primary',
//       ];
//     } else {
//       $form['galleries_to_cleanup']['#title'] = $this->t('Scan Results');
//       $form['galleries_to_cleanup']['#description'] = $this->t('No orphaned image references found in any galleries.');
//       $form['galleries_to_cleanup']['#access'] = FALSE; // Hide the empty checkboxes container
//     }

//     return $form;
//   }

//   public function submitForm(array &$form, FormStateInterface $form_state)
//   {
//     $selected_gallery_ids = array_filter($form_state->getValue('galleries_to_cleanup'));
//     $gallery_storage = $this->entityTypeManager->getStorage('gallery');
//     $cleaned_count_total = 0;
//     $galleries_processed_count = 0;

//     if (empty($selected_gallery_ids)) {
//       $this->messenger()->addWarning($this->t('No galleries were selected for cleanup.'));
//       return;
//     }

//     foreach (array_keys($selected_gallery_ids) as $gallery_id) {
//       /** @var \Drupal\user_galleries\Entity\Gallery $gallery */
//       $gallery = $gallery_storage->load($gallery_id);
//       if (!$gallery) {
//         continue;
//       }

//       $original_image_items = $gallery->get('images')->getValue();
//       $valid_image_items = [];
//       $gallery_cleaned_references_count = 0;

//       foreach ($original_image_items as $item) {
//         if (empty($item['target_id']) || !File::load($item['target_id'])) {
//           $gallery_cleaned_references_count++;
//         } else {
//           $valid_image_items[] = $item; // Keep valid references
//         }
//       }

//       if ($gallery_cleaned_references_count > 0) {
//         $gallery->set('images', $valid_image_items);
//         $gallery->save();
//         $this->messenger()->addStatus($this->t('Cleaned @count orphaned image references from gallery "@label".', [
//           '@count' => $gallery_cleaned_references_count,
//           '@label' => $gallery->label(),
//         ]));
//         $cleaned_count_total += $gallery_cleaned_references_count;
//       }
//       $galleries_processed_count++;
//     }

//     if ($cleaned_count_total > 0) {
//       $this->messenger()->addStatus($this->t('Successfully cleaned a total of @total_cleaned orphaned references from @galleries_count galleries.', [
//         '@total_cleaned' => $cleaned_count_total,
//         '@galleries_count' => $galleries_processed_count,
//       ]));
//     } elseif ($galleries_processed_count > 0) {
//       $this->messenger()->addStatus($this->t('Selected galleries were checked. No references required cleaning based on the current scan, or they were already clean.'));
//     }
//   }
// }
