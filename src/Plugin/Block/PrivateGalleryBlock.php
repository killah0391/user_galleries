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

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new PrivateGalleryBlock instance.
   *
   * @param array $configuration
   * A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   * The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   * The plugin implementation definition.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   * The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   * The current route match.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, AccountInterface $current_user, EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $route_match)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('current_route_match')
    );
  }

  /**
   * Gets the user object from the current route.
   *
   * @return \Drupal\user\UserInterface|null
   * The user object or NULL if not found.
   */
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

    // Only build if we are on a user profile page.
    if (!$profile_user) {
      return [];
    }

    // Check if the current user can view this private gallery.
    $can_view = ($this->currentUser->id() === $profile_user->id()) || $this->currentUser->hasPermission('view any private gallery');

    if (!$can_view) {
      return []; // Do not show anything if no permission.
    }

    $galleries = $this->entityTypeManager->getStorage('gallery')->loadByProperties([
      'uid' => $profile_user->id(),
      'gallery_type' => 'private',
    ]);

    if ($galleries) {
      $gallery = reset($galleries);
      $view_builder = $this->entityTypeManager->getViewBuilder('gallery');
      $build['gallery'] = $view_builder->view($gallery, 'full');

      // Check if the current user can manage this gallery.
      $can_manage = ($this->currentUser->id() === $profile_user->id()) || $this->currentUser->hasPermission('manage user galleries');

      if ($can_manage) {
        $build['manage_link'] = [
          '#type' => 'link',
          '#title' => $this->t('Manage Private Gallery'),
          '#url' => Url::fromRoute('user_galleries.manage_private', ['user' => $profile_user->id()]),
          '#attributes' => ['class' => ['button', 'button--small']],
          '#weight' => 100,
        ];
      }
      return $build;
    }

    // Optionally show a message if the profile user *could* have a private
    // gallery but doesn't, and the viewer is the owner.
    if ($this->currentUser->id() === $profile_user->id()) {
      return [
        '#markup' => $this->t('You do not have a private gallery yet.'),
      ];
    }

    // If viewer has permission but owner has no gallery, show nothing or a message.
    // Showing nothing is often preferred for 'private' sections unless admin.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts()
  {
    // Vary by user and URL to handle different profiles and permissions.
    return parent::getCacheContexts() + ['user', 'url.path', 'user.permissions'];
  }
}
