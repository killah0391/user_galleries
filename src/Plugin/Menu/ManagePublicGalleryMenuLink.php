<?php
// File: user_match/src/Plugin/Menu/ManagePublicGalleryMenuLink.php

namespace Drupal\user_galleries\Plugin\Menu;

use Drupal\Core\Menu\MenuLinkDefault;
use Drupal\Core\Menu\StaticMenuLinkOverridesInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a menu link that dynamically points to the current user's profile.
 */
class ManagePublicGalleryMenuLink extends MenuLinkDefault
{

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new ManagePublicGalleryMenuLink object.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, StaticMenuLinkOverridesInterface $static_override, AccountInterface $current_user)
  {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $static_override);
    $this->currentUser = $current_user;
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
      $container->get('menu_link.static.overrides'),
      $container->get('current_user') // Inject the current_user service.
    );
  }

  /**
   * {@inheritdoc}
   * The route name is static, pointing to the user profile page.
   */
  public function getRouteName()
  {
    return 'user_galleries.manage_public';
  }

  /**
   * {@inheritdoc}
   * Dynamically set the route parameter to the current user's ID.
   */
  public function getRouteParameters()
  {
    return ['user' => $this->currentUser->id()];
  }

  /**
   * {@inheritdoc}
   * Control the visibility of the link.
   */
  public function isEnabled()
  {
    // Only enable the link for users who are not anonymous.
    return $this->currentUser->isAuthenticated();
  }

  /**
   * {@inheritdoc}
   * You can also make the title dynamic if needed.
   */
  public function getTitle()
  {
    if ($this->currentUser->isAuthenticated()) {
      // Example of a dynamic title.
      $name = $this->currentUser->getDisplayName();
      return $this->t('Manage public gallery');
    }
    return parent::getTitle();
  }
}
