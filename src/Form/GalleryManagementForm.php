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
use Drupal\match_toasts\Ajax\ShowBootstrapToastsCommand; // Assuming this is used by your ajaxRefreshFormCallback
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
    $logger = \Drupal::logger('user_galleries'); // Standard logger for this form
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
        '#name' => 'profile_picture_selection',
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
            '#width' => 100,
            '#height' => 100,
          ];
          $row['filename'] = ['#markup' => $file->getFilename()];
          $row['actions']['delete_icon'] = [
            '#type' => 'markup',
            '#markup' => '<a href="#" role="button" class="gallery-delete-icon-trigger" data-fid="' . $fid . '" title="' . $this->t('Delete this image') . '"><i class="bi bi-trash text-danger"></i></a>',
            '#weight' => -10,
            '#allowed_tags' => ['a', 'i'],
          ];
          $row['actions']['delete_submit_fid_' . $fid] = [
            '#type' => 'submit',
            '#name' => 'delete_image_' . $fid,
            '#value' => $this->t('Confirm Delete for FID @fid', ['@fid' => $fid]),
            '#submit' => ['::deleteImageSubmit'],
            '#ajax' => [
              'callback' => '::ajaxRefreshFormCallback',
              'wrapper' => 'form-container-gallery',
            ],
            '#limit_validation_errors' => [],
            '#attributes' => [
              'class' => ['gallery-action-delete-submit', 'gallery-action-delete-submit-fid-' . $fid, 'visually-hidden'],
            ],
            '#weight' => -5,
          ];

          if ($gallery_type === 'public' && $target_user->hasField('user_picture')) {
            $default_value_for_radio = ($current_profile_picture_fid == $fid) ? $fid : NULL;
            $row['set_picture'] = [
              '#type' => 'radio',
              '#title' => $this->t('Use this image'),
              '#title_display' => 'invisible',
              '#name' => 'profile_picture_selection',
              '#return_value' => (string)$fid,
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
      $form['allowed_users'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Grant Access To'),
        '#description' => $this->t('Users who can view this private gallery (besides you).'),
        '#target_type' => 'user',
        '#tags' => TRUE,
        '#default_value' => $gallery->get('allowed_users')->referencedEntities(),
        '#selection_settings' => ['include_anonymous' => FALSE]
      ];
      // Logging added in the previous response to confirm field addition
      if (isset($form['allowed_users'])) {
        $logger->debug('BuildForm - form[allowed_users] successfully added to form structure for private gallery. Type: @type, Tags: @tags', [
          '@type' => $form['allowed_users']['#type'],
          '@tags' => !empty($form['allowed_users']['#tags']) ? 'TRUE' : 'FALSE',
        ]);
      } else {
        $logger->warning('BuildForm - form[allowed_users] was NOT added to form structure for private gallery ID @gid, even though gallery type is private.', ['@gid' => $gallery->id()]);
      }
    }

    $form['#attached']['library'][] = 'user_galleries/global-styling';
    $form['#attached']['library'][] = 'match_toasts/global-handler';
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
          // Ensure ShowBootstrapToastsCommand is available if you use it
          if (class_exists('\Drupal\match_toasts\Ajax\ShowBootstrapToastsCommand')) {
            $response->addCommand(new ShowBootstrapToastsCommand($message_text, $title, $type));
          }
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

        if ($target_user_id && $gallery_type === 'public') {
          $user_entity = User::load($target_user_id);
          if ($user_entity && $user_entity->hasField('user_picture')) {
            $current_profile_picture_target_id = $user_entity->get('user_picture')->target_id;
            $logger->info('deleteImageSubmit: Current profile picture FID for user @uid is @current_fid', ['@uid' => $target_user_id, '@current_fid' => $current_profile_picture_target_id ?: 'None']);

            if (!empty($current_profile_picture_target_id) && $current_profile_picture_target_id == $fid_to_delete) {
              $logger->info('deleteImageSubmit: Deleting image @fid which is current profile picture. Resetting user_picture.', ['@fid' => $fid_to_delete]);
              $user_entity->get('user_picture')->setValue([]);
              $user_entity->save();
              $this->messenger()->addStatus($this->t('Your profile picture has been removed because the image was deleted from your gallery.'));
              $form_state->set('profile_picture_cleared_due_to_delete', TRUE);
              $logger->info('deleteImageSubmit: Flagged that profile picture was cleared due to delete.');
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

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $logger = \Drupal::logger('user_galleries_form_submit');

    // --- DETAILED INPUT LOGGING (as per previous response) ---
    $all_form_values = $form_state->getValues();
    $logger->debug('SubmitForm - START - All values from $form_state->getValues(): <pre>@values</pre>', ['@values' => var_export($all_form_values, TRUE)]);

    $user_input = $form_state->getUserInput();
    $logger->debug('SubmitForm - START - Raw user input from $form_state->getUserInput(): <pre>@input</pre>', ['@input' => var_export($user_input, TRUE)]);

    $allowed_users_explicit_get_value = $form_state->getValue('allowed_users');
    $logger->debug('SubmitForm - START - Value from $form_state->getValue("allowed_users"): @value', ['@value' => var_export($allowed_users_explicit_get_value, TRUE)]);
    // --- END OF DETAILED INPUT LOGGING ---

    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'] ?? 'N/A';

    if ($triggering_element && $triggering_element['#type'] === 'submit' && strpos($button_name, 'delete_image_') === 0) {
      $logger->info('SubmitForm: Delete button (@name) triggered. Main submit logic for other fields skipped.', ['@name' => $button_name]);
      return;
    }

    /** @var \Drupal\user_galleries\Entity\Gallery $gallery */
    $gallery = $form_state->get('gallery');
    $target_user_id = $form_state->get('target_user_id');
    $gallery_type = $form_state->get('gallery_type');
    $changes_made = FALSE;

    $logger->info('SubmitForm: Processing main submit. Gallery ID: @gid, Target User ID: @target_uid, Gallery Type: @gallery_type', [
      '@gid' => $gallery->id(),
      '@target_uid' => $target_user_id,
      '@gallery_type' => $gallery_type
    ]);

    // --- Profile Picture Logic (copied from your existing form for consistency) ---
    $userInputForProfilePic = $user_input; // Use $user_input already fetched.
    $selected_pic_value = null;
    if (isset($userInputForProfilePic['profile_picture_selection']) && $userInputForProfilePic['profile_picture_selection'] !== '') {
      $selected_pic_value = (string)$userInputForProfilePic['profile_picture_selection'];
      $logger->info('SubmitForm: Profile picture selection from user input: @selection', ['@selection' => $selected_pic_value]);
    } else {
      $logger->info('SubmitForm: No profile picture selection found in user input.');
    }

    if ($gallery_type === 'public' && $selected_pic_value !== null) {
      $user = User::load($target_user_id);
      if ($user && $user->hasField('user_picture')) {
        $current_pic_fid_obj = $user->get('user_picture')->first();
        $current_pic_fid = $current_pic_fid_obj ? (string)$current_pic_fid_obj->get('target_id')->getValue() : NULL;
        $logger->info('SubmitForm: Current profile FID for user @uid: @current_fid. Selected for update: @selected_pic_value', ['@uid' => $target_user_id, '@current_fid' => $current_pic_fid ?: 'None', '@selected_pic_value' => $selected_pic_value]);

        if ($selected_pic_value === 'clear') {
          if ($current_pic_fid !== NULL) {
            $user->get('user_picture')->setValue([]);
            $user->save();
            $this->messenger()->addStatus($this->t('Profile picture removed.'));
            // $changes_made = TRUE; // This change is to User entity, not Gallery entity directly.
          }
        } elseif (is_numeric($selected_pic_value) && $selected_pic_value != $current_pic_fid) {
          $user->get('user_picture')->setValue(['target_id' => $selected_pic_value]);
          $user->save();
          $this->messenger()->addStatus($this->t('Profile picture updated successfully.'));
          // $changes_made = TRUE; // User entity change.
        }
      } else {
        $logger->warning('SubmitForm: User @uid or user_picture field not found for profile pic update.', ['@uid' => $target_user_id]);
      }
    }
    // --- End of Profile Picture Logic ---


    // --- Handle 'allowed_users' for private gallery (with fix for NULL/empty string) ---
    if ($gallery->get('gallery_type')->value === 'private') {
      // $allowed_users_form_value was already retrieved and logged at the start of submitForm via $allowed_users_explicit_get_value
      $allowed_users_form_value = $allowed_users_explicit_get_value;

      // Get the raw user input again specifically for 'allowed_users' if needed for normalization logic
      $raw_allowed_users_input = $user_input['allowed_users'] ?? NULL;
      $logger->debug('Allowed Users - Raw input from $form_state->getUserInput()["allowed_users"] for normalization check: @raw_input', ['@raw_input' => var_export($raw_allowed_users_input, TRUE)]);


      if ($allowed_users_form_value === NULL && is_string($raw_allowed_users_input) && trim($raw_allowed_users_input) === '') {
        $logger->info('Allowed Users - Normalizing NULL from getValue() to an empty array because raw input for "allowed_users" was an empty string.');
        $allowed_users_form_value = [];
      }

      if ($allowed_users_form_value !== NULL) {
        $current_allowed_users_entity_values = $gallery->get('allowed_users')->getValue();
        $logger->debug('Allowed Users - Current entity values (raw): <pre>@values</pre>', ['@values' => var_export($current_allowed_users_entity_values, TRUE)]);

        $current_allowed_ids = array_map(function ($ref) {
          return (string)$ref['target_id'];
        }, $current_allowed_users_entity_values);
        sort($current_allowed_ids, SORT_STRING);
        $logger->debug('Allowed Users - Current sorted IDs from entity: [@ids_str]', ['@ids_str' => json_encode($current_allowed_ids)]);

        $new_allowed_ids = array_map(function ($ref) {
          if (isset($ref['target_id'])) {
            return (string)$ref['target_id'];
          }
          return NULL;
        }, (array)$allowed_users_form_value);
        $new_allowed_ids = array_filter($new_allowed_ids);
        sort($new_allowed_ids, SORT_STRING);
        $logger->debug('Allowed Users - New sorted IDs from form submission (after potential normalization): [@ids_str]', ['@ids_str' => json_encode($new_allowed_ids)]);

        $are_lists_different = ($current_allowed_ids != $new_allowed_ids);
        $logger->debug('Allowed Users - Comparing current IDs to new IDs. Different? @result', [
          '@result' => $are_lists_different ? 'YES' : 'NO',
        ]);

        if ($are_lists_different) {
          $gallery->set('allowed_users', $allowed_users_form_value);
          $changes_made = TRUE;
          $this->messenger()->addStatus($this->t('Access list updated.'));
          $logger->info('Allowed Users - Change detected. List set for gallery ID @gallery_id.', ['@gallery_id' => $gallery->id()]);
        } else {
          $logger->info('Allowed Users - Lists are identical. No change to allowed_users for gallery ID @gallery_id.', ['@gallery_id' => $gallery->id()]);
        }
      } else {
        $logger->warning('Allowed Users - Processed $allowed_users_form_value is still NULL for private gallery ID @gallery_id. No update will be attempted for this field.', ['@gallery_id' => $gallery->id()]);
      }
    }
    // --- End of 'allowed_users' handling ---


    // --- Handle new image uploads ---
    $fids_uploaded = $form_state->getValue('upload_images');
    if (!empty($fids_uploaded) && is_array($fids_uploaded)) {
      $logger->info('SubmitForm: Found @count FIDs to upload from upload_images.', ['@count' => count($fids_uploaded)]);
      $image_field = $gallery->get('images');
      $new_count = 0;
      foreach ($fids_uploaded as $fid) {
        if ($file = File::load($fid)) {
          $file->setPermanent();
          $file->save();
          $image_field->appendItem($file);
          $changes_made = TRUE;
          $new_count++;
        } else {
          $logger->warning('SubmitForm: Could not load file for FID @fid during upload.', ['@fid' => $fid]);
        }
      }
      if ($new_count > 0) {
        $this->messenger()->addStatus($this->t('Added @count new images.', ['@count' => $new_count]));
      }
    }
    // --- End of new image uploads ---

    if ($changes_made) {
      $gallery->save();
      $logger->info('Gallery ID @gallery_id SAVED because changes_made flag was true.', ['@gallery_id' => $gallery->id()]);
      // Optional: $this->galleryStorage->resetCache([$gallery->id()]);
    } else {
      $all_messages = $this->messenger()->all();
      if (empty($all_messages)) {
        // Only add this generic message if no other specific message (like profile update) was added.
        $this->messenger()->addMessage($this->t('No changes were made to the gallery content itself.'));
      }
      $logger->info('Gallery ID @gallery_id NOT saved for gallery-specific fields because changes_made flag was false.', ['@gallery_id' => $gallery->id()]);
    }

    $form_state->setValue('upload_images', []);
    $input_for_rebuild = $form_state->getUserInput();
    if (isset($input_for_rebuild['upload_images'])) {
      unset($input_for_rebuild['upload_images']);
    }
    if (isset($input_for_rebuild['upload_images_upload_button'])) {
      unset($input_for_rebuild['upload_images_upload_button']);
    }
    $form_state->setUserInput($input_for_rebuild);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Route title callback.
   */
  public static function getTitle(UserInterface $user, $gallery_type)
  {
    // Make sure to inject the Translation service if using $this->t() in a static method,
    // or use global \Drupal::translation()->translate() or simply t().
    return t('Manage @username\'s @type Gallery (Admin)', [
      '@username' => $user->getDisplayName(),
      '@type' => ucfirst($gallery_type),
    ]);
  }
}
