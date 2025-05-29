<?php

namespace Drupal\user_galleries\Form;

use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\user\Entity\User;
use Drupal\Core\Form\FormBase;
use Drupal\user\UserInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\SettingsCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\match_toasts\Ajax\ShowBootstrapToastsCommand;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Provides a form for managing user galleries.
 */
class GalleryManagementForm extends FormBase
{

  protected $currentUser;
  protected $entityTypeManager;
  protected $galleryStorage;
  protected $renderer;

  public function __construct(AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer)
  {
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->galleryStorage = $this->entityTypeManager->getStorage('gallery');
    $this->renderer = $renderer;
    $this->setMessenger(\Drupal::messenger());
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('renderer')
    );
  }

  public function getFormId()
  {
    return 'user_galleries_management_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $gallery_type = NULL, UserInterface $user = NULL)
  {
    $logger = \Drupal::logger('user_galleries');
    $target_user = $user ?: User::load($this->currentUser->id());

    if (!$target_user) {
      $form['message'] = ['#markup' => $this->t('User not found.')];
      $logger->warning('BuildForm: Target user not found. Current UID: @uid', ['@uid' => $this->currentUser->id()]);
      return $form;
    }
    $logger->info('BuildForm: Target User ID: @target_uid, Gallery Type: @gallery_type', [
      '@target_uid' => $target_user->id(),
      '@gallery_type' => $gallery_type,
    ]);

    if ($this->currentUser->id() !== $target_user->id() && !$this->currentUser->hasPermission('manage user galleries')) {
      $logger->warning('BuildForm: Access denied for UID @current_uid to manage gallery for UID @target_uid.', [
        '@current_uid' => $this->currentUser->id(),
        '@target_uid' => $target_user->id(),
      ]);
      throw new AccessDeniedHttpException('You do not have permission to manage this gallery.');
    }
    if (!$gallery_type || !in_array($gallery_type, ['public', 'private'])) {
      $logger->error('BuildForm: Invalid gallery type provided: @gallery_type', ['@gallery_type' => $gallery_type]);
      throw new \InvalidArgumentException('Invalid gallery type provided.');
    }

    $galleries = $this->galleryStorage->loadByProperties(['uid' => $target_user->id(), 'gallery_type' => $gallery_type]);
    $gallery = NULL;
    if ($galleries) {
      $gallery = reset($galleries);
      $logger->info('BuildForm: Loaded existing gallery ID @gallery_id for UID @target_uid and type @gallery_type.', [
        '@gallery_id' => $gallery->id(),
        '@target_uid' => $target_user->id(),
        '@gallery_type' => $gallery_type,
      ]);
    } else {
      $gallery = $this->galleryStorage->create([
        'uid' => $target_user->id(),
        'gallery_type' => $gallery_type,
        'title' => ucfirst($gallery_type) . ' Gallery for ' . $target_user->getDisplayName(),
      ]);
      $gallery->save();
      $logger->info('BuildForm: Created new gallery ID @gallery_id for UID @target_uid and type @gallery_type.', [
        '@gallery_id' => $gallery->id(),
        '@target_uid' => $target_user->id(),
        '@gallery_type' => $gallery_type,
      ]);
      $this->messenger()->addStatus($this->t('A new @type gallery has been created.', ['@type' => $gallery_type]));
    }
    $form_state->set('gallery', $gallery);
    $form_state->set('target_user_id', $target_user->id());
    $form_state->set('gallery_type', $gallery_type);

    $form['#prefix'] = '<div class="form-container" id="form-container-gallery">';
    $form['#suffix'] = '</div>';

    $form['title'] = ['#type' => 'html_tag', '#tag' => 'h2', '#value' => $this->t('Manage @username\'s @type Gallery', ['@username' => $target_user->getDisplayName(), '@type' => ucfirst($gallery_type)])];

    $form['existing_images_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'existing-images-wrapper']
    ];

    $header = [
      'image' => $this->t('Image'),
      'filename' => $this->t('Filename'),
      'actions' => $this->t('Actions'),
    ];

    $current_profile_picture_fid = NULL;
    if ($gallery_type === 'public' && $target_user->hasField('user_picture')) {
      $header['set_picture'] = $this->t('Set as Profile Picture');
      $current_profile_picture_fid = $target_user->get('user_picture')->target_id;
      $logger->info('BuildForm: Current profile picture FID for user @uid is @fid.', ['@uid' => $target_user->id(), '@fid' => $current_profile_picture_fid ?: 'None']);

      $form['existing_images_wrapper']['profile_picture_selection_clear'] = [
        '#type' => 'radio',
        '#title' => $this->t('None (Clear Profile Picture)'),
        '#name' => 'profile_picture_selection', // Shared name for the radio group
        '#return_value' => 'clear',
        '#default_value' => !$current_profile_picture_fid ? 'clear' : NULL,
        '#attributes' => ['id' => 'profile-pic-option-clear'],
        '#prefix' => '<div class="profile-pic-none-option-wrapper">',
        '#suffix' => '</div>',
      ];
      $logger->info('BuildForm: Added "Clear Profile Picture" radio option. Default checked: @checked', ['@checked' => !$current_profile_picture_fid ? 'Yes' : 'No']);
    }

    $form['existing_images_wrapper']['existing_images_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => [],
      '#empty' => $this->t('There are no images in this gallery yet.'),
      '#attributes' => ['id' => 'existing-images-table'],
    ];

    $images = $gallery->get('images');
    if (!$images->isEmpty()) {
      foreach ($images as $delta => $image_item) {
        if ($image_item->target_id && ($file = File::load($image_item->target_id))) {
          $fid = $file->id();
          $row = [];
          $row['image'] = [
            '#theme' => 'image_style',
            '#style_name' => 'thumbnail',
            '#uri' => $file->getFileUri(),
            '#width' => 100, // Optional: for layout consistency
            '#height' => 100, // Optional: for layout consistency
          ];
          $row['filename'] = ['#markup' => $file->getFilename()];
          $row['actions']['delete_icon'] = [
            '#type' => 'markup',
            '#markup' => '<a href="#" role="button" class="gallery-delete-icon-trigger" data-fid="' . $fid . '" title="' . $this->t('Delete this image') . '"><i class="bi bi-trash text-danger"></i></a>',
            '#weight' => -10, // To appear before other actions if any
            '#allowed_tags' => ['a', 'i'], // Drupal best practice for markup
          ];

          // Hidden Submit Button (for the actual AJAX action)
          // We use a unique name for FAPI element key, but the #name property is what matters for submission.
          $row['actions']['delete_submit_fid_' . $fid] = [
            '#type' => 'submit',
            '#name' => 'delete_image_' . $fid, // This name MUST match what deleteImageSubmit expects
            '#value' => $this->t('Confirm Delete for FID @fid', ['@fid' => $fid]), // For non-JS fallback or accessibility
            '#submit' => ['::deleteImageSubmit'], // Your existing submit handler
            '#ajax' => [
              'callback' => '::ajaxRefreshFormCallback', // Your existing AJAX callback
              'wrapper' => 'form-container-gallery',
            ],
            '#limit_validation_errors' => [],
            '#attributes' => [
              // 'visually-hidden' is a Drupal core class to hide elements accessibly.
              // Add a specific class to target this button with JavaScript.
              'class' => ['gallery-action-delete-submit', 'gallery-action-delete-submit-fid-' . $fid, 'visually-hidden'],
            ],
            '#weight' => -5, // Place it after the icon markup if needed, but it's hidden.
          ];

          if ($gallery_type === 'public' && $target_user->hasField('user_picture')) {
            $default_value_for_radio = ($current_profile_picture_fid == $fid) ? $fid : NULL;
            $row['set_picture'] = [
              '#type' => 'radio',
              '#title' => $this->t('Use this image'),
              '#title_display' => 'invisible', // Keep label for accessibility, but hide it
              '#name' => 'profile_picture_selection', // Shared name for the radio group
              '#return_value' => (string)$fid, // Ensure return value is string if FIDs are numeric
              '#default_value' => $default_value_for_radio ? (string)$default_value_for_radio : NULL,
              '#attributes' => ['class' => ['profile-pic-radio']],
            ];
            $logger->info('BuildForm: Added radio button for FID @fid. Default checked: @checked', ['@fid' => $fid, '@checked' => $default_value_for_radio ? 'Yes' : 'No']);
          }
          $form['existing_images_wrapper']['existing_images_table'][$fid] = $row;
        }
      }
    }

    $form['upload_images'] = ['#type' => 'managed_file', '#title' => $this->t('Upload New Images'), '#upload_location' => ($gallery_type === 'private') ? 'private://galleries/' . $target_user->id() : 'public://galleries/' . $target_user->id(), '#multiple' => TRUE, '#upload_validators' => ['file_validate_extensions' => ['png jpg jpeg gif']], '#description' => $this->t('Allowed extensions: png, jpg, jpeg, gif. Click "Save Gallery Changes" after selecting files.')];
    if ($gallery_type === 'private') {
      $form['allowed_users'] = ['#type' => 'entity_autocomplete', '#title' => $this->t('Grant Access To'), '#description' => $this->t('Users who can view this private gallery (besides you).'), '#target_type' => 'user', '#tags' => TRUE, '#default_value' => $gallery->get('allowed_users')->referencedEntities(), '#selection_settings' => ['include_anonymous' => FALSE]];
    }

    $form['#attached']['library'][] = 'user_galleries/global-styling';
    $form['#attached']['library'][] = 'match_toasts/global-handler'; // For bootstrap toasts
    $form['#attached']['library'][] = 'user_galleries/gallery-management-js';
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save Gallery Changes'), '#ajax' => ['callback' => '::ajaxRefreshFormCallback', 'wrapper' => 'form-container-gallery']];
    return $form;
  }

  public function ajaxRefreshFormCallback(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();
    $messenger = \Drupal::messenger();
    $all_messages_by_type = $messenger->all();

    if (!empty($all_messages_by_type)) {
      foreach ($all_messages_by_type as $type => $messages_in_type) {
        foreach ($messages_in_type as $message_markup) {
          $message_text = (string) $message_markup;
          $title = $this->t('Notification');
          $lower_message_text = strtolower($message_text);

          if (str_contains($lower_message_text, 'deleted')) {
            $title = $this->t('Deletion Confirmed');
          } elseif (str_contains($lower_message_text, 'profile picture updated')) {
            $title = $this->t('Profile Updated');
          } elseif (str_contains($lower_message_text, 'profile picture removed')) {
            $title = $this->t('Profile Picture Cleared');
          } elseif (str_contains($lower_message_text, 'saved')) {
            $title = $this->t('Saved Successfully');
          } elseif (str_contains($lower_message_text, 'updated')) {
            $title = $this->t('Update Complete');
          } elseif (str_contains($lower_message_text, 'created')) {
            $title = $this->t('Creation Successful');
          } elseif (str_contains($lower_message_text, 'added')) {
            if (str_contains($lower_message_text, 'new images')) {
              $title = $this->t('Images Added');
            } else {
              $title = $this->t('Item Added');
            }
          } elseif (str_contains($lower_message_text, 'access list updated')) {
            $title = $this->t('Permissions Changed');
          } elseif (str_contains($lower_message_text, 'no changes were made')) {
            $title = $this->t('Information');
          }

          if ($title === $this->t('Notification')) {
            switch ($type) {
              case 'status':
                $title = $this->t('Status');
                break;
              case 'warning':
                $title = $this->t('Warning');
                break;
              case 'error':
                $title = $this->t('Error');
                break;
            }
          }
          $response->addCommand(new ShowBootstrapToastsCommand($message_text, $title, $type));
        }
      }
      $messenger->deleteAll();
    }

    $response->addCommand(new ReplaceCommand('#form-container-gallery', $form));
    $response->addCommand(new SettingsCommand(['user_galleries' => ['resetUploadField' => TRUE]], TRUE));
    return $response;
  }

  public function deleteImageSubmit(array &$form, FormStateInterface $form_state)
  {
    $logger = \Drupal::logger('user_galleries');
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];
    $logger->info('deleteImageSubmit: Triggered by button: @button_name', ['@button_name' => $button_name]);

    if (strpos($button_name, 'delete_image_') === 0) {
      $fid_to_delete = substr($button_name, strlen('delete_image_'));
      $logger->info('deleteImageSubmit: Extracted FID to delete: @fid', ['@fid' => $fid_to_delete]);

      if (is_numeric($fid_to_delete) && ($file_entity_to_delete = File::load($fid_to_delete))) {
        $gallery = $form_state->get('gallery');
        $target_user_id = $form_state->get('target_user_id');
        $gallery_type = $form_state->get('gallery_type');
        $logger->info('deleteImageSubmit: Target User ID: @target_uid, Gallery Type: @gallery_type', ['@target_uid' => $target_user_id, '@gallery_type' => $gallery_type]);

        if ($target_user_id && $gallery_type === 'public') { // Only check profile pic for public gallery context
          $user_entity = User::load($target_user_id);
          if ($user_entity && $user_entity->hasField('user_picture')) {
            $current_profile_picture_target_id = $user_entity->get('user_picture')->target_id;
            $logger->info('deleteImageSubmit: Current profile picture FID for user @uid is @current_fid', ['@uid' => $target_user_id, '@current_fid' => $current_profile_picture_target_id ?: 'None']);

            if (!empty($current_profile_picture_target_id) && $current_profile_picture_target_id == $fid_to_delete) {
              $logger->info('deleteImageSubmit: Deleting image @fid which is current profile picture. Resetting user_picture.', ['@fid' => $fid_to_delete]);
              $user_entity->get('user_picture')->setValue([]);
              $user_entity->save();
              $this->messenger()->addStatus($this->t('Your profile picture has been removed because the image was deleted from your gallery.'));

              // Ensure the "None (Clear Profile Picture)" radio is selected in the rebuilt form
              if (isset($form['existing_images_wrapper']['profile_picture_selection_clear'])) {
                // This change to $form won't persist to the rebuilt form directly unless $form_state->setRebuild is used
                // and the form builder re-evaluates default_values.
                // More robustly, the default_value logic in buildForm will handle this.
                // For immediate effect in *this* AJAX refresh, this might be needed if not relying on full rebuild.
                // However, the current AJAX callback replaces the whole form, so buildForm's defaults will apply.
                $form_state->set('profile_picture_cleared_due_to_delete', TRUE); // Flag for buildForm if needed, or rely on current_pic_fid being null
                $logger->info('deleteImageSubmit: Flagged that profile picture was cleared due to delete.');
              }
            }
          } else {
            $logger->warning('deleteImageSubmit: User @uid not loaded or has no user_picture field for profile pic check.', ['@uid' => $target_user_id]);
          }
        }

        $images_field = $gallery->get('images');
        $images_values = $images_field->getValue();
        $images_to_keep = [];
        $deleted_from_gallery = FALSE;

        foreach ($images_values as $image_item) {
          if ($image_item['target_id'] != $fid_to_delete) {
            $images_to_keep[] = $image_item;
          } else {
            $deleted_from_gallery = TRUE;
          }
        }

        if ($deleted_from_gallery) {
          $gallery->set('images', $images_to_keep);
          $gallery->save();
          $this->messenger()->addStatus($this->t('Image "%filename" has been deleted from the gallery.', ['%filename' => $file_entity_to_delete->getFilename()]));
          $logger->info('deleteImageSubmit: Image FID @fid deleted from gallery ID @gallery_id.', ['@fid' => $fid_to_delete, '@gallery_id' => $gallery->id()]);
        } else {
          $this->messenger()->addWarning($this->t('Image was not found in this gallery to delete.'));
          $logger->warning('deleteImageSubmit: Image FID @fid not found in gallery ID @gallery_id.', ['@fid' => $fid_to_delete, '@gallery_id' => $gallery->id()]);
        }
      } else {
        $this->messenger()->addError($this->t('Could not delete image. File ID not found or invalid.'));
        $logger->error('deleteImageSubmit: Could not delete image. FID @fid not numeric or file not loaded.', ['@fid' => $fid_to_delete]);
      }
    }
    $form_state->setRebuild(TRUE);
  }

  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    parent::validateForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $logger = \Drupal::logger('user_galleries');
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'] ?? 'N/A';

    if ($triggering_element && $triggering_element['#type'] === 'submit' && strpos($button_name, 'delete_image_') === 0) {
      $logger->info('submitForm: Delete button (@name) triggered, main submit logic skipped as it is handled by deleteImageSubmit.', ['@name' => $button_name]);
      return;
    }

    /** @var \Drupal\user_galleries\Entity\Gallery $gallery */
    $gallery = $form_state->get('gallery');
    $target_user_id = $form_state->get('target_user_id');
    $gallery_type = $form_state->get('gallery_type');
    $changes_made = FALSE;

    $logger->info('submitForm: Processing main submit. Target User ID: @target_uid, Gallery Type: @gallery_type', ['@target_uid' => $target_user_id, '@gallery_type' => $gallery_type]);

    // Log different ways of getting values for debugging this specific issue
    $values_from_getValues = $form_state->getValues();
    $logger->debug('submitForm: Full $form_state->getValues() output: @values', ['@values' => print_r($values_from_getValues, TRUE)]);

    $value_from_getValue = $form_state->getValue('profile_picture_selection');
    $logger->debug('submitForm: $form_state->getValue("profile_picture_selection") output: @value', ['@value' => $value_from_getValue ?? 'NULL']);

    $userInput = $form_state->getUserInput();
    $logger->debug('submitForm: Full $form_state->getUserInput() output: @input', ['@input' => print_r($userInput, TRUE)]);

    // --- Determine selected profile picture ---
    $selected_pic_value = null;

    // Priority 1: Check raw user input, as we've seen 'profile_picture_selection' in the payload
    if (isset($userInput['profile_picture_selection']) && $userInput['profile_picture_selection'] !== '') {
      $selected_pic_value = (string)$userInput['profile_picture_selection']; // Ensure string for comparison
      $logger->info('submitForm: Profile picture selection is "@value" (derived from $form_state->getUserInput()).', ['@value' => $selected_pic_value]);
    } else {
      // This case means 'profile_picture_selection' was not found directly in user input.
      // This would be surprising given the payload you've shown.
      // It might happen if *no* radio in the group was selected by the user AND no default was posted.
      $logger->warning('submitForm: "profile_picture_selection" was NOT found as a key in $form_state->getUserInput() or it was empty. This is unexpected if a radio was selected.');
      // As a deep fallback for diagnostic purposes, let's see if the previous $values parsing yields anything
      if (isset($values_from_getValues['profile_picture_selection_clear']) && $values_from_getValues['profile_picture_selection_clear'] === 'clear') {
        $selected_pic_value = 'clear';
        $logger->info('submitForm: Deep Fallback: Profile picture selection is "clear" (from $values_from_getValues[\'profile_picture_selection_clear\']).');
      } elseif (isset($values_from_getValues['existing_images_table']) && is_array($values_from_getValues['existing_images_table'])) {
        foreach ($values_from_getValues['existing_images_table'] as $fid_in_table => $row_values) {
          if (isset($row_values['set_picture']) && !empty($row_values['set_picture']) && is_numeric($row_values['set_picture'])) {
            $selected_pic_value = (string)$row_values['set_picture'];
            $logger->info('submitForm: Deep Fallback: Profile picture selection is FID @fid (from $values_from_getValues path for table).', ['@fid' => $selected_pic_value]);
            break;
          }
        }
      }
    }
    // --- End of determining selected profile picture ---

    // Handle profile picture update if public gallery and a selection was determined
    if ($gallery_type === 'public') {
      if ($selected_pic_value !== null) {
        $logger->info('submitForm: Attempting to update profile picture with determined selection: @selection', ['@selection' => $selected_pic_value]);
        $user = User::load($target_user_id);

        if ($user && $user->hasField('user_picture')) {
          $logger->info('submitForm: User @uid loaded and has user_picture field for update.', ['@uid' => $target_user_id]);
          $current_pic_fid_obj = $user->get('user_picture')->first();
          // Important: Ensure $current_pic_fid is also a string for comparison, or consistently handle types.
          $current_pic_fid = $current_pic_fid_obj ? (string)$current_pic_fid_obj->get('target_id')->getValue() : NULL;

          $logger->info('submitForm: Current profile FID for user @uid before update: @current_fid', ['@uid' => $target_user_id, '@current_fid' => $current_pic_fid ?: 'None']);

          if ($selected_pic_value === 'clear') {
            if ($current_pic_fid !== NULL) {
              $logger->info('submitForm: Clearing profile picture for user @uid.', ['@uid' => $target_user_id]);
              $user->get('user_picture')->setValue([]);
              $user->save();
              $this->messenger()->addStatus($this->t('Profile picture removed.'));
              $changes_made = TRUE;
              $logger->info('submitForm: User @uid saved after clearing profile picture.', ['@uid' => $target_user_id]);
            } else {
              $logger->info('submitForm: Profile picture already clear for user @uid. No change made by "clear" selection.', ['@uid' => $target_user_id]);
            }
          } elseif (is_numeric($selected_pic_value) && $selected_pic_value != $current_pic_fid) {
            $logger->info('submitForm: Setting profile picture for user @uid to FID @new_fid.', ['@uid' => $target_user_id, '@new_fid' => $selected_pic_value]);
            $user->get('user_picture')->setValue(['target_id' => $selected_pic_value]);
            $user->save();
            $this->messenger()->addStatus($this->t('Profile picture updated successfully.'));
            $changes_made = TRUE;
            $logger->info('submitForm: User @uid saved after updating profile picture to FID @new_fid.', ['@uid' => $target_user_id, '@new_fid' => $selected_pic_value]);
          } elseif (is_numeric($selected_pic_value) && $selected_pic_value == $current_pic_fid) {
            $logger->info('submitForm: Selected profile picture FID @fid is already the current one for user @uid. No change made by this selection.', ['@fid' => $selected_pic_value, '@uid' => $target_user_id]);
          } else {
            // This might catch cases where $selected_pic_value is numeric but not 'clear' and doesn't fit other conditions.
            $logger->warning('submitForm: Invalid or unhandled numeric value for profile_picture_selection: "@value". Current FID: "@current_fid"', ['@value' => $selected_pic_value, '@current_fid' => $current_pic_fid ?: 'None']);
          }
        } else {
          $logger->error('submitForm: User @uid not loaded or does not have user_picture field for update.', ['@uid' => $target_user_id]);
        }
      } else {
        $logger->info('submitForm: No new profile picture selection was made or determined (selected_pic_value is NULL). No profile picture change attempted.');
      }
    } else {
      $logger->info('submitForm: Gallery type is not public, skipping profile picture update logic.');
    }

    // Handle new image uploads.
    $fids_uploaded = $form_state->getValue('upload_images');
    if (!empty($fids_uploaded) && is_array($fids_uploaded)) {
      $logger->info('submitForm: Found @count FIDs to upload from upload_images.', ['@count' => count($fids_uploaded)]);
      $image_field = $gallery->get('images');
      $new_count = 0;
      foreach ($fids_uploaded as $fid) {
        if ($file = File::load($fid)) {
          $file->setPermanent();
          $file->save();
          $image_field->appendItem($file);
          $changes_made = TRUE;
          $new_count++;
          // $logger->info('submitForm: Added FID @fid to gallery.', ['@fid' => $fid]); // Can be noisy
        } else {
          $logger->warning('submitForm: Could not load file for FID @fid during upload.', ['@fid' => $fid]);
        }
      }
      if ($new_count > 0) {
        $this->messenger()->addStatus($this->t('Added @count new images.', ['@count' => $new_count]));
      }
    }

    // Handle allowed users for private gallery.
    if ($gallery->get('gallery_type')->value === 'private') {
      $allowed_users_value = $form_state->getValue('allowed_users');
      if ($allowed_users_value !== NULL) {
        $current_allowed_users_refs = $gallery->get('allowed_users')->getValue();
        $current_allowed_ids = array_map(function ($ref) {
          return $ref['target_id'];
        }, $current_allowed_users_refs);

        $new_allowed_user_values = $allowed_users_value;
        $new_allowed_ids = array_map(function ($ref) {
          return $ref['target_id'];
        }, $new_allowed_user_values);
        sort($current_allowed_ids);
        sort($new_allowed_ids);

        if ($current_allowed_ids != $new_allowed_ids) {
          $gallery->set('allowed_users', $new_allowed_user_values);
          $changes_made = TRUE;
          $this->messenger()->addStatus($this->t('Access list updated.'));
          $logger->info('submitForm: Allowed users list updated for private gallery ID @gallery_id.', ['@gallery_id' => $gallery->id()]);
        }
      }
    }

    if ($changes_made) {
      $gallery->save();
      $logger->info('submitForm: Gallery ID @gallery_id saved due to changes.', ['@gallery_id' => $gallery->id()]);
    }

    $form_state->setValue('upload_images', []);
    $input_for_rebuild = $form_state->getUserInput(); // Use a different var name to avoid confusion with $userInput for selection
    if (isset($input_for_rebuild['upload_images'])) unset($input_for_rebuild['upload_images']);
    if (isset($input_for_rebuild['upload_images_upload_button'])) unset($input_for_rebuild['upload_images_upload_button']);
    $form_state->setUserInput($input_for_rebuild);
    $form_state->setRebuild(TRUE);
  }
}
