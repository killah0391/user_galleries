<?php

namespace Drupal\user_galleries;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Access controller for the Gallery entity.
 */
class GalleryAccessControlHandler extends EntityAccessControlHandler
{

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account)
  {
    /** @var \Drupal\user_galleries\Entity\Gallery $entity */

    switch ($operation) {
      case 'view':
        if ($entity->get('gallery_type')->value === 'public') {
          return AccessResult::allowed()->cachePerPermissions();
        }

        if ($entity->get('gallery_type')->value === 'private') {
          $is_owner_result = AccessResult::allowedIf($account->id() === $entity->getOwnerId())
            ->addCacheContexts(['user']); // Line 31

          $is_in_allowed_users = AccessResult::neutral(); // Line 33
          $allowed_users_field = $entity->get('allowed_users'); // Line 34
          foreach ($allowed_users_field as $allowed_user_item) { // Line 35 (error reported on this line)
            if ($allowed_user_item->target_id == $account->id()) { // Line 36
              $is_in_allowed_users = AccessResult::allowed()->addCacheContexts(['user']); // Line 37 (actual call on AccessResultAllowed)
              break;
            }
          }

          $can_view_any_private_result = AccessResult::allowedIfHasPermission($account, 'view any private gallery');
          $can_manage_galleries_result = AccessResult::allowedIfHasPermission($account, 'manage user galleries');

          return $is_owner_result
            ->orIf($is_in_allowed_users)
            ->orIf($can_view_any_private_result)
            ->orIf($can_manage_galleries_result)
            ->addCacheableDependency($entity);
        }
        return AccessResult::neutral()->addCacheableDependency($entity)->addCacheContexts(['user']);


      case 'update':
      case 'delete':
        $is_owner_result = AccessResult::allowedIf($account->id() === $entity->getOwnerId())
          ->addCacheContexts(['user']);

        $has_admin_permission_result = AccessResult::allowedIfHasPermission($account, 'manage user galleries');

        return $is_owner_result->orIf($has_admin_permission_result)->addCacheableDependency($entity);
    }
    return AccessResult::neutral()->addCacheableDependency($entity)->addCacheContexts(['user.permissions']);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL)
  {
    return AccessResult::forbidden();
  }
}
