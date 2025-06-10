<?php

namespace Drupal\user_galleries\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drupal\user\Entity\User;
use Drupal\match_abuse\Service\BlockCheckerInterface;
use Drupal\Core\Render\Markup;

/**
 * Provides a 'Private Gallery' block for a viewed user.
 *
 * @Block(
 * id = "private_gallery_block",
 * admin_label = @Translation("Private Gallery Block"),
 * )
 */
class PrivateGalleryBlock extends BlockBase implements ContainerFactoryPluginInterface
{

  protected $currentUser;
  protected $entityTypeManager;
  protected $routeMatch;
  /**
   * The match abuse block checker service.
   *
   * @var \Drupal\match_abuse\Service\BlockCheckerInterface
   */
  protected $blockChecker;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $route_match, BlockCheckerInterface $block_checker)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
    $this->blockChecker = $block_checker;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
      $container->get('match_abuse.block_checker')
    );
  }

  protected function getProfileUser(): ?UserInterface
  {
    $user = $this->routeMatch->getParameter('user');
    if ($user instanceof UserInterface) {
      return $user;
    }
    if (is_numeric($user)) {
      return User::load($user);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function build()
  {
    $profile_user = $this->getProfileUser();
    if (!$profile_user) {
      return [];
    }

    // Explicitly hint for static analysis that $profile_user (UserInterface)
    // is being used as an AccountInterface for the block check.
    /** @var \Drupal\Core\Session\AccountInterface $profile_user_as_account */
    $profile_user_as_account = $profile_user;
    // If current user and profile user have a block active, hide the block.
    if ($this->blockChecker->isBlockActive($this->currentUser, $profile_user_as_account)) {
      return [];
    }

    // Base view access: owner or global "view any" or "manage" permission.
    $has_initial_view_permission = ($this->currentUser->id() === $profile_user->id()) ||
      $this->currentUser->hasPermission('view any private gallery') ||
      $this->currentUser->hasPermission('manage user galleries');

    $galleries = $this->entityTypeManager->getStorage('gallery')->loadByProperties([
      'uid' => $profile_user->id(),
      'gallery_type' => 'private',
    ]);
    $gallery_entity = $galleries ? reset($galleries) : NULL;

    if ($gallery_entity) {
      // If not owner and no global view/manage permission, check allowed_users list.
      if (
        $this->currentUser->id() !== $profile_user->id() &&
        !$this->currentUser->hasPermission('view any private gallery') &&
        !$this->currentUser->hasPermission('manage user galleries')
      ) {

        $allowed_users_field = $gallery_entity->get('allowed_users')->getValue();
        $allowed_user_ids = array_column($allowed_users_field, 'target_id');
        if (!in_array($this->currentUser->id(), $allowed_user_ids)) {
          return []; // Not owner, no global perm, and not in allowed_users list for this gallery.
        }
      }
      // If we reach here, the user has permission to see this specific gallery
      // or at least the block (further access checks happen at entity/field level).
    } elseif (!$has_initial_view_permission) {
      // No gallery entity exists, and user doesn't have broad permissions to view if one did.
      return [];
    }
    // If gallery_entity is null but user is owner/manager, they can still see the block (e.g., to manage/create).


    $block_label = $this->configuration['label'];
    $manage_icon_link_render_array = NULL;
    $images_data = [];
    $message_markup = NULL;

    $can_manage = ($this->currentUser->id() === $profile_user->id()) || $this->currentUser->hasPermission('manage user galleries');
    if ($can_manage) {
      $manage_gallery_text = $this->t('Manage Private Gallery');
      $manage_icon_link_render_array = [
        '#type' => 'link',
        '#title' => Markup::create('<i class="bi bi-gear-fill"></i> <span class="visually-hidden">' . $manage_gallery_text . '</span>'),
        '#url' => Url::fromRoute('user_galleries.manage_private', ['user' => $profile_user->id()]),
        '#attributes' => [
          'class' => ['gallery-manage-icon-link'],
          'title' => $manage_gallery_text,
        ],
      ];
    }

    if ($gallery_entity) {
      $image_field_items = $gallery_entity->get('images');
      if (!$image_field_items->isEmpty()) {
        $image_style_storage = $this->entityTypeManager->getStorage('image_style');
        $file_url_generator = \Drupal::service('file_url_generator');
        $list_thumbnail_style = $image_style_storage->load('wide'); // Ensure 'thumbnail' style exists

        foreach ($image_field_items as $item) {
          if ($file = $item->entity) {
            $image_uri = $file->getFileUri();
            $list_image_url = $list_thumbnail_style ?
              $file_url_generator->generateString($list_thumbnail_style->buildUrl($image_uri)) :
              $file_url_generator->generateString($image_uri);
            $original_image_url = $file_url_generator->generateAbsoluteString($image_uri);

            $images_data[] = [
              'uri' => $list_image_url,
              'alt' => $item->alt ?? $file->getFilename(),
              'title_attr' => $item->title ?? '',
              'original_src' => $original_image_url,
            ];
          }
        }
      }
    }

    $has_images = !empty($images_data);
    // Message logic for private gallery
    if (!$has_images) {
      // Only show "no gallery" type messages if the user is the owner or has manage rights.
      // Otherwise, for other users who might have view access (e.g. allowed_users), an empty gallery should just be empty.
      if ($this->currentUser->id() === $profile_user->id()) {
        $message_markup = $this->t('You do not have a private gallery yet, or it is empty.');
      } elseif ($this->currentUser->hasPermission('manage user galleries') && !$gallery_entity) {
        // Admin/Manager looking at a user who hasn't even had one created.
        $message_markup = $this->t('@username does not have a private gallery yet.', ['@username' => $profile_user->getDisplayName()]);
      }
    }

    // Final check if there's anything to render for the current user.
    // An allowed user viewing an empty private gallery of someone else should see nothing if block_label is empty.
    if (empty($block_label) && empty($manage_icon_link_render_array) && !$has_images && empty($message_markup)) {
      // If user is not owner and no manage rights, and nothing else to show.
      if ($this->currentUser->id() !== $profile_user->id() && !$this->currentUser->hasPermission('manage user galleries')) {
        return [];
      }
    }


    return [
      '#theme' => 'private_gallery_block', // Your custom theme hook
      '#images' => $images_data,
      '#title_text' => $block_label,
      '#manage_icon_link' => $manage_icon_link_render_array,
      '#user' => $profile_user,
      '#has_images' => $has_images,
      '#message' => $message_markup,
      '#attributes' => ['class' => ['private-gallery-block-content-wrapper']],
      '#attached' => [
        'library' => [
          'user_galleries/global-styling',
          'user_galleries/gallery-management-js',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts()
  {
    return parent::getCacheContexts() + ['user', 'url.path', 'user.permissions', 'user.roles:authenticated'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags()
  {
    $tags = parent::getCacheTags();
    // The style name 'wide' was used in the build method.
    $tags[] = 'config:image.style.wide';
    if ($profile_user = $this->getProfileUser()) {
      $tags[] = 'user:' . $profile_user->id();
      // For private galleries, cache tags need to be very carefully considered
      // if visibility depends on the allowed_users field.
      // A general tag for this user's private galleries might be:
      $tags[] = 'user_galleries_private_list:user:' . $profile_user->id();

      // If specific gallery entities are loaded, their tags are important.
      $galleries = $this->entityTypeManager->getStorage('gallery')->loadByProperties([
        'uid' => $profile_user->id(),
        'gallery_type' => 'private',
      ]);
      foreach ($galleries as $gallery) {
        $tags = array_merge($tags, $gallery->getCacheTags());
      }
      $tags[] = 'match_abuse_block_list';
    }
    return $tags;
  }

}
