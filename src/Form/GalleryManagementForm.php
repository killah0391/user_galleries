<?php

namespace Drupal\user_galleries\Form;

use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\user\Entity\User;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Render\Markup;
use Drupal\user\UserInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\SettingsCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\match_abuse\Service\BlockCheckerInterface;
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
  /**
   * The block checker service.
   *
   * @var \Drupal\match_abuse\Service\BlockCheckerInterface
   */
  protected $blockChecker;

  public function __construct(AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, BlockCheckerInterface $block_checker)
  {
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->galleryStorage = $this->entityTypeManager->getStorage('gallery');
    $this->renderer = $renderer;
    $this->blockChecker = $block_checker;
    $this->setMessenger(\Drupal::messenger());
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('match_abuse.block_checker')
    );
  }

  public function getFormId()
  {
    return 'user_galleries_management_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $gallery_type = NULL, UserInterface $user = NULL)
  {
    $target_user = $user ?: User::load($this->currentUser->id());

    if (!$target_user) {
      $form['message'] = ['#markup' => $this->t('User not found.')];
      return $form;
    }

    if ($this->currentUser->id() !== $target_user->id() && !$this->currentUser->hasPermission('manage user galleries')) {
      throw new AccessDeniedHttpException('You do not have permission to manage this gallery.');
    }
    if (!$gallery_type || !in_array($gallery_type, ['public', 'private'])) {
      throw new \InvalidArgumentException('Invalid gallery type provided.');
    }

    $galleries = $this->galleryStorage->loadByProperties(['uid' => $target_user->id(), 'gallery_type' => $gallery_type]);
    $gallery = NULL;
    if ($galleries) {
      $gallery = reset($galleries);
    } else {
      $gallery = $this->galleryStorage->create([
        'uid' => $target_user->id(),
        'gallery_type' => $gallery_type,
        'title' => ucfirst($gallery_type) . ' Gallery for ' . $target_user->getDisplayName(),
      ]);
      $gallery->save();
      $this->messenger()->addStatus($this->t('A new @type gallery has been created.', ['@type' => $gallery_type]));
    }
    $form_state->set('gallery', $gallery);
    $form_state->set('target_user_id', $target_user->id());
    $form_state->set('gallery_type', $gallery_type);

    $form['#prefix'] = '<div class="form-container card card-body mb-4" id="form-container-gallery">';
    $form['#suffix'] = '</div>';

    if (
      $this->currentUser->id() !== $target_user->id() && $this->currentUser->hasPermission('manage user galleries')) {
      $form['admin_title'] = [
        '#type' => 'html_tag', // Changed from 'title' to 'admin_title' to avoid conflict
        '#tag' => 'h4',
        '#value' => $this->t('Managing Gallery for: @username (@userid)', ['@username' => $target_user->getDisplayName(), '@userid' => $target_user->id()]),        '#attributes' => ['class' => ['mb-3', 'text-muted']],
      ];
      }
    $form['existing_images_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'existing-images-wrapper']
    ];

    $current_profile_picture_fid = NULL;
    if ($gallery_type === 'public' && $target_user->hasField('user_picture')) {
      $current_profile_picture_fid = $target_user->get('user_picture')->target_id;
    }

    $images = $gallery->get('images');
    $form['upload_images_wrapper'] = [
      '#type' => 'details',
      '#title' => $this->t('Upload New Images'),
      '#open' => TRUE, // Default to open
      '#attributes' => ['class' => ['mb-3']],
    ];

    $form['upload_images_wrapper']['upload_images'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Select images'),
      '#title_display' => 'invisible', // Title provided by details wrapper
      '#upload_location' => ($gallery_type === 'private') ? 'private://galleries/' . $target_user->id() : 'public://galleries/' . $target_user->id(),
      '#multiple' => TRUE,
      '#upload_validators' => ['file_validate_extensions' => ['png jpg jpeg gif']],
      '#description' => $this->t('Allowed extensions: png, jpg, jpeg, gif. Click "Save Gallery Changes" after selecting files.'),
      '#progress' => [
        'type' => 'throbber',
        'message' => $this->t('Uploading image...'),
      ],
    ];

    if ($gallery_type === 'private') {
      $form['allowed_users_wrapper'] = [
        '#type' => 'details',
        '#title' => $this->t('Manage Access for Private Gallery'),
        '#open' => TRUE,
        '#attributes' => ['class' => ['mb-3']],
      ];
      $user_storage = $this->entityTypeManager->getStorage('user');
      // Load active users, excluding anonymous.
      // You might want to exclude the gallery owner ($target_user->id()) as well,
      // as the description implies "Users who can view this private gallery (besides you)."
      $query = $user_storage->getQuery()
        ->condition('status', 1)
        ->condition('uid', 0, '>'); // Exclude anonymous

      // If you want to exclude the gallery owner from being selectable in this list:
      // $query->condition('uid', $target_user->id(), '<>');

      $uids = $query->accessCheck(TRUE)->execute();
      $user_entities = $user_storage->loadMultiple($uids);

      $user_options = [];
      foreach ($user_entities as $user_entity) {
        // Do not include users that the gallery owner ($target_user) has blocked.
        if ($this->blockChecker->isUserBlockedBy($user_entity, $target_user)) {
          continue;
        }
        // Do not include users who have blocked the gallery owner ($target_user).
        if ($this->blockChecker->isUserBlockedBy($target_user, $user_entity)) {
          continue;
        }

        $user_picture = $user_entity->get('user_picture')->entity;
        if ($user_picture) {
          $picture_url = \Drupal::service('file_url_generator')->generateAbsoluteString($user_picture->getFileUri());
        } else {
          $config = \Drupal::config('field.field.user.user.user_picture');
          $default_image = $config->get('settings.default_image');
          $file_entity_default = \Drupal::service('entity.repository')
            ->loadEntityByUuid('file', $default_image['uuid']);
          if ($file_entity_default) {
            $picture_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file_entity_default->getFileUri());
          } else {
            // Fallback if default image UUID is broken or file missing
            $picture_url = \Drupal::service('file_url_generator')->generateAbsoluteString(\Drupal::config('user.settings')->get('anonymous_picture.path'));
          }
        }
        $user_options[$user_entity->id()] = Markup::create('<img class="rounded-5" style="width:30px;height:30px;" src="' . $picture_url . '"/> ' . $user_entity->getDisplayName());
      }
      asort($user_options);

      $default_allowed_user_ids = [];
      if ($gallery && !$gallery->get('allowed_users')->isEmpty()) {
        foreach ($gallery->get('allowed_users')->referencedEntities() as $allowed_user) {
          // Check if gallery owner ($target_user) has blocked this $allowed_user.
          $owner_blocked_allowed_user = $this->blockChecker->isUserBlockedBy($allowed_user, $target_user);
          // Check if this $allowed_user has blocked the gallery owner ($target_user).
          $allowed_user_blocked_owner = $this->blockChecker->isUserBlockedBy($target_user, $allowed_user);

          if (!$owner_blocked_allowed_user && !$allowed_user_blocked_owner) {
            $default_allowed_user_ids[] = $allowed_user->id();
          }
        }
      }
      $form['allowed_users_wrapper']['allowed_users'] = [
        '#type' => 'select', // Changed from 'entity_autocomplete'
        '#title' => $this->t('Grant Access To'),
        '#description' => $this->t('Users who can view this private gallery (besides you). Select one or more users.'),
        '#options' => $user_options,
        '#multiple' => TRUE,
        '#chosen' => TRUE,
        '#default_value' => $default_allowed_user_ids,
        '#attributes' => [
          // Add a class if you want to specifically target this with Chosen JS,
          // or rely on the Chosen module's global configuration.
          // 'class' => ['make-this-field-chosen'],
          'data-placeholder' => $this->t('Select users...'), // Chosen uses this
        ],
        '#attached' => [
          'library' => [
            // This assumes the Chosen Drupal module is installed and defines this library.
            // The exact name might be 'chosen/chosen.drupal' or 'chosen/drupal.chosen'.
            // Check the Chosen module's .libraries.yml file. 'chosen/drupal.chosen' is common.
            'chosen/drupal.chosen',
          ],
        ],
      ];
    }

    // Image Grid Display
    $form['existing_images_wrapper']['image_grid_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['mt-3']], // Spacing for the grid section
    ];

    if ($gallery_type === 'public' && $target_user->hasField('user_picture')) {
      $form['existing_images_wrapper']['image_grid_container']['profile_picture_management_description'] = [
        '#type' => 'container',
        '#markup' => t('<small>Upload new images, select profile picture or delete images.</small>'),
        '#attributes' => ['class' => ['mb-3']],
      ];
    }

    if ($gallery_type === 'private') {
      $form['existing_images_wrapper']['image_grid_container']['management_description'] = [
        '#type' => 'container',
        '#markup' => '<small>Upload new images, manage access for your private gallery or delete images.</small>',
        '#attributes' => ['class' => ['mb-4']],
      ];
    }

    $form['existing_images_wrapper']['image_grid_container']['image_grid'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row']], // Bootstrap row for grid
    ];

    if (!$images->isEmpty()) {
      foreach ($images as $delta => $image_item) {
        if ($image_item->target_id && ($file = File::load($image_item->target_id))) {
          $fid = $file->id();

          $form['existing_images_wrapper']['image_grid_container']['image_grid'][$fid] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['col-md-4 col-sm-6 mb-4']], // Bootstrap column
            'card' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['card', 'h-100']], // Bootstrap card
              'image' => [
                '#theme' => 'image_style',
                '#style_name' => 'thumb', // Or another appropriate style
                '#width' => 300,
                '#height' => 300,
                '#uri' => $file->getFileUri(),
                '#attributes' => ['class' => ['card-img-top']], // Style image within card
              ],
              'card_body' => [
                '#type' => 'container',
                '#attributes' => ['class' => ['card-body', 'd-flex', 'flex-column', 'p-0']],
                'actions_group' => [
                  '#type' => 'container',
                  '#attributes' => ['class' => ['btn-group', 'mt-auto', 'w-100']],
                  '#weight' => 100,
                ],
              ],
            ],
          ];

          // "Set as Profile Picture" Radio
          if ($gallery_type === 'public' && $target_user->hasField('user_picture')) {
            $default_value_for_radio = ($current_profile_picture_fid == $fid) ? $fid : NULL;
            $is_current_pfp = ($current_profile_picture_fid == $fid);
            $form['existing_images_wrapper']['image_grid_container']['image_grid'][$fid]['card']['card_body']['actions_group']['set_pfp'] = [
              '#type' => 'submit',
              '#name' => 'set_pfp_' . $fid,
              '#value' => $this->t(''),
              '#submit' => ['::setPfpSubmit'],
              '#ajax' => [
                'callback' => '::ajaxRefreshFormCallback',
                'wrapper' => 'form-container-gallery',
              ],
              '#attributes' => [
                'class' => ['btn', 'btn-sm', 'btn-success', 'pfp-button-fid', 'rounded-top-0'],
                'title' => $this->t('Set this image as profile picture'),
                'style' => ['width:80%;'],
              ],
              '#disabled' => $is_current_pfp,
              '#limit_validation_errors' => [],
            ];
          }

          // Delete Action
          $form['existing_images_wrapper']['image_grid_container']['image_grid'][$fid]['card']['card_body']['actions_group']['delete'] = [
            '#type' => 'submit',
            '#name' => 'delete_image_' . $fid,
            '#value' => $this->t(''),
            '#submit' => ['::deleteImageSubmit'],
            '#ajax' => [
              'callback' => '::ajaxRefreshFormCallback',
              'wrapper' => 'form-container-gallery',
            ],
            '#attributes' => [
              'class' => ['btn', 'btn-sm', 'btn-danger', 'gallery-delete-trigger-new', 'rounded-top-0'],
              'title' => $this->t('Delete this image'),
              'style' => ['width:20%;'],
            ],
            '#limit_validation_errors' => [],
          ];
        }
      }
    } else {
        $form['existing_images_wrapper']['image_grid_container']['no_images_message'] = [
            '#markup' => '<p class="text-muted">' . $this->t('There are no images in this gallery yet.') . '</p>',
        ];
    }
    $form['#attached']['library'][] = 'user_galleries/global-styling';
    $form['#attached']['library'][] = 'user_galleries/gallery-management-js';
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save Gallery Changes'), '#ajax' => ['callback' => '::ajaxRefreshFormCallback', 'wrapper' => 'form-container-gallery'], '#attributes' => ['class' => ['btn', 'btn-success', 'text-white', 'mt-4']]]; // Added mt-4
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
          // Convert markup to string to ensure it's plain text for MessageCommand.
          $message_text = (string) $message_markup;
          // Add the message using Drupal's core MessageCommand.
          // The messages will be rendered by Drupal's standard status messages system.
          // The $type ('status', 'warning', 'error') is automatically handled by MessageCommand
          // when it adds the message to \Drupal::messenger().
          $response->addCommand(new MessageCommand($message_text, NULL, ['type' => $type]));
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
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if (strpos($button_name, 'delete_image_') === 0) {
      $fid_to_delete = substr($button_name, strlen('delete_image_'));

      if (is_numeric($fid_to_delete) && ($file_entity_to_delete = File::load($fid_to_delete))) {
        $gallery = $form_state->get('gallery');
        $target_user_id = $form_state->get('target_user_id');
        $gallery_type = $form_state->get('gallery_type');

        if ($target_user_id && $gallery_type === 'public') {
          $user_entity = User::load($target_user_id);
          if ($user_entity && $user_entity->hasField('user_picture')) {
            $current_profile_picture_target_id = $user_entity->get('user_picture')->target_id;


            if (!empty($current_profile_picture_target_id) && $current_profile_picture_target_id == $fid_to_delete) {

              $user_entity->get('user_picture')->setValue([]);
              $user_entity->save();
              $this->messenger()->addStatus($this->t('Your profile picture has been removed because the image was deleted from your gallery.'));
              $form_state->set('profile_picture_cleared_due_to_delete', TRUE);

            }
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
          $this->messenger()->addStatus($this->t('The image has been deleted from your @gallery_type gallery!', ['@gallery_type' => $gallery_type]));
        } else {
          $this->messenger()->addWarning($this->t('Image was not found in this gallery to delete.'));
        }
      } else {
        $this->messenger()->addError($this->t('Could not delete image. File not found or invalid.'));
      }
    }
    $form_state->setRebuild(TRUE);
  }

  public function setPfpSubmit(array &$form, FormStateInterface $form_state) {

    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];


    if (strpos($button_name, 'set_pfp_') === 0) {
      $fid_to_set = substr($button_name, strlen('set_pfp_'));


      if (is_numeric($fid_to_set) && ($file_entity_to_set = File::load($fid_to_set))) {
        $target_user_id = $form_state->get('target_user_id');
        $user = User::load($target_user_id);

        if ($user && $user->hasField('user_picture')) {
          $current_pic_fid_obj = $user->get('user_picture')->first();
          $current_pic_fid = $current_pic_fid_obj ? (string)$current_pic_fid_obj->get('target_id')->getValue() : NULL;

          if ($current_pic_fid == $fid_to_set) {
            $this->messenger()->addStatus($this->t('This image is already your profile picture.'));
          } else {
            $user->get('user_picture')->setValue(['target_id' => $fid_to_set]);
            $user->save();
            $this->messenger()->addStatus($this->t('Profile picture updated successfully.'));

          }
        } else {
          $this->messenger()->addError($this->t('Could not set profile picture. User or field not found.'));

        }
      } else {
        $this->messenger()->addError($this->t('Could not set profile picture. File not found or invalid FID.'));

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


    // --- DETAILED INPUT LOGGING (as per previous response) ---
    $all_form_values = $form_state->getValues();


    $user_input = $form_state->getUserInput();


    $allowed_users_explicit_get_value = $form_state->getValue('allowed_users');


    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'] ?? 'N/A';

    if ($triggering_element && $triggering_element['#type'] === 'submit' && strpos($button_name, 'delete_image_') === 0) {

      return;
    }

    /** @var \Drupal\user_galleries\Entity\Gallery $gallery */
    $gallery = $form_state->get('gallery');
    $target_user_id = $form_state->get('target_user_id');
    $gallery_type = $form_state->get('gallery_type');
    $changes_made = FALSE;


    // --- Profile Picture Logic (Handling "Clear PFP" from radio) ---
    $userInputForProfilePic = $form_state->getUserInput();
    $selected_pic_value_from_radio = $userInputForProfilePic['profile_picture_selection'] ?? null;

    if ($gallery_type === 'public' && $selected_pic_value_from_radio === 'clear') {
      $user = User::load($target_user_id);
      if ($user && $user->hasField('user_picture')) {
        $current_pic_fid_obj = $user->get('user_picture')->first();
        $current_pic_fid = $current_pic_fid_obj ? (string)$current_pic_fid_obj->get('target_id')->getValue() : NULL;

        if ($current_pic_fid !== NULL) {
          $user->get('user_picture')->setValue([]);
          $user->save();
          $this->messenger()->addStatus($this->t('Profile picture removed.'));

        }
      }
    }




    if ($gallery->get('gallery_type')->value === 'private') {

      $allowed_users_form_value = $allowed_users_explicit_get_value;

      // Get the raw user input again specifically for 'allowed_users' if needed for normalization logic
      $raw_allowed_users_input = $user_input['allowed_users'] ?? NULL;

      if ($allowed_users_form_value === NULL && is_string($raw_allowed_users_input) && trim($raw_allowed_users_input) === '') {
        $allowed_users_form_value = [];
      }

      if ($allowed_users_form_value !== NULL) {
        $current_allowed_users_entity_values = $gallery->get('allowed_users')->getValue();

        $current_allowed_ids = array_map(function ($ref) {
          return (string)$ref['target_id'];
        }, $current_allowed_users_entity_values);
        sort($current_allowed_ids, SORT_STRING);

        $new_allowed_ids = array_map(function ($ref) {
          if (isset($ref['target_id'])) {
            return (string)$ref['target_id'];
          }
          return NULL;
        }, (array)$allowed_users_form_value);
        $new_allowed_ids = array_filter($new_allowed_ids);
        sort($new_allowed_ids, SORT_STRING);

        $are_lists_different = ($current_allowed_ids != $new_allowed_ids);

        if ($are_lists_different) {
          $gallery->set('allowed_users', $allowed_users_form_value);
          $changes_made = TRUE;
          $this->messenger()->addStatus($this->t('Access list updated.'));

        }
      }
    }




    $fids_uploaded = $form_state->getValue('upload_images');
    if (!empty($fids_uploaded) && is_array($fids_uploaded)) {

      $image_field = $gallery->get('images');
      $new_count = 0;
      foreach ($fids_uploaded as $fid) {
        if ($file = File::load($fid)) {
          $file->setPermanent();
          $file->save();
          $image_field->appendItem($file);
          $changes_made = TRUE;
          $new_count++;
        }
      }
      if ($new_count > 0) {
        $this->messenger()->addStatus($this->t('Added @count new images.', ['@count' => $new_count]));
      }
    }
    // --- End of new image uploads ---

    if ($changes_made) {
      $gallery->save();
    } else {
      $all_messages = $this->messenger()->all();
      if (empty($all_messages)) {
        $this->messenger()->addMessage($this->t('No changes were made to the gallery content itself.'));
      }
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
  public static function getTitle($gallery_type)
  {
    // Make sure to inject the Translation service if using $this->t() in a static method,
    // or use global \Drupal::translation()->translate() or simply t().
    return t('Manage @type gallery', ['@type' => $gallery_type]);
  }
}
