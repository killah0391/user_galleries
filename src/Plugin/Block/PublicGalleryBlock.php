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
 * Provides a 'Public Gallery' block for a viewed user.
 *
 * @Block(
 * id = "public_gallery_block",
 * admin_label = @Translation("Public Gallery Block"),
 * )
 */
class PublicGalleryBlock extends BlockBase implements ContainerFactoryPluginInterface
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

    $block_label = $this->configuration['label'];
    $manage_icon_link_render_array = NULL;
    $images_data = [];
    $message_markup = NULL;

    $can_manage = ($this->currentUser->id() === $profile_user->id()) || $this->currentUser->hasPermission('manage user galleries');
    if ($can_manage) {
      $manage_gallery_text = $this->t('Manage Public Gallery');
      $manage_icon_link_render_array = [
        '#type' => 'link',
        '#title' => Markup::create('<i class="bi bi-gear-fill"></i> <span class="visually-hidden">' . $manage_gallery_text . '</span>'),
        '#url' => Url::fromRoute('user_galleries.manage_public', ['user' => $profile_user->id()]),
        '#attributes' => [
          'class' => ['gallery-manage-icon-link'], // Styled by your CSS
          'title' => $manage_gallery_text,
        ],
      ];
    }

    $galleries = $this->entityTypeManager->getStorage('gallery')->loadByProperties([
      'uid' => $profile_user->id(),
      'gallery_type' => 'public',
    ]);

    if ($galleries) {
      $gallery = reset($galleries);
      $image_field_items = $gallery->get('images');

      if (!$image_field_items->isEmpty()) {
        /** @var \Drupal\image\ImageStyleStorageInterface $image_style_storage */
        $image_style_storage = $this->entityTypeManager->getStorage('image_style');
        /** @var \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator */
        $file_url_generator = \Drupal::service('file_url_generator');

        foreach ($image_field_items as $item) {
          /** @var \Drupal\file\Entity\File $file */
          if ($file = $item->entity) {
            $image_uri = $file->getFileUri();
            $original_image_url = $file_url_generator->generateAbsoluteString($image_uri);

            $images_data[] = [
              'styled_image' => [
                '#theme' => 'responsive_image',
                '#responsive_image_style_id' => 'gallery_thumbnail',
                '#uri' => $image_uri,
                '#attributes' => [
                  'class' => ['js-zoomable-image'],
                  'alt' => $item->alt ?? $file->getFilename(),
                  'title' => $item->title ?? '',
                  'loading' => 'lazy',
                  'data-zoom-src' => $original_image_url,
                ],
              ],
            ];
          }
        }
      }
    }
    $has_images = !empty($images_data);
    if (!$has_images) {
      if ($this->currentUser->id() === $profile_user->id()) {
        $message_markup = $this->t('You do not have a public gallery yet.');
      } else {
        $message_markup = $this->t('@username does not have a public gallery.', ['@username' => $profile_user->getDisplayName()]);
      }
    }

    if (empty($block_label) && empty($manage_icon_link_render_array) && !$has_images && empty($message_markup)) {
      return [];
    }

    return [
      '#theme' => 'public_gallery_block', // Defined in user_galleries.module
      '#images' => $images_data,
      '#title_text' => $block_label,
      '#manage_icon_link' => $manage_icon_link_render_array,
      '#user' => $profile_user,
      '#has_images' => $has_images,
      '#message' => $message_markup,
      '#attributes' => ['class' => ['public-gallery-block-content-wrapper']], // Class for the outer div in Twig
      '#attached' => [
        'library' => [
          'user_galleries/global-styling',    // Loads your user_galleries.css
          'user_galleries/gallery-management-js', // Loads your user_galleries.js
          'match_chat/match_chat_image_zoom',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts()
  {
    return parent::getCacheContexts() + ['user', 'url.path', 'user.permissions'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags()
  {
    $tags = parent::getCacheTags();
    // Add image style a general cache tag for invalidation if styles change.
    // The responsive style 'gallery_thumbnail' is now used.
    $tags[] = 'config:responsive_image.style.gallery_thumbnail';

    if ($profile_user = $this->getProfileUser()) {
      $tags[] = 'user:' . $profile_user->id();
      $galleries = $this->entityTypeManager->getStorage('gallery')->loadByProperties([
        'uid' => $profile_user->id(),
        'gallery_type' => 'public', // Specific to this block
      ]);
      foreach ($galleries as $gallery) {
        $tags = array_merge($tags, $gallery->getCacheTags());
      }
      $tags[] = 'match_abuse_block_list';
    }
    return $tags;
  }
}
