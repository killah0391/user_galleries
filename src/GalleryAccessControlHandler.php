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
    $owner_id = $entity->getOwnerId();

    switch ($operation) {
      case 'view':
        // If it's public, anyone can view.
        if ($entity->get('gallery_type')->value === 'public') {
          return AccessResult::allowed()->cachePerPermissions();
        }

        // If it's private, check owner OR allowed users.
        if ($entity->get('gallery_type')->value === 'private') {
          // Check if user is the owner.
          if ($account->id() === $owner_id) {
            return AccessResult::allowed()->addCacheableDependency($entity)->addCacheableDependency($account);
          }

          // Check if user is in the allowed_users list.
          $allowed_users = $entity->get('allowed_users')->getValue();
          $allowed_ids = array_column($allowed_users, 'target_id');
          if (in_array($account->id(), $allowed_ids)) {
            return AccessResult::allowed()->addCacheableDependency($entity)->addCacheableDependency($account);
          }
        }
        // Deny private access otherwise.
        return AccessResult::forbidden()->addCacheableDependency($entity)->addCacheableDependency($account);


      case 'update':
      case 'delete':
        // Only the owner can edit or delete.
        return AccessResult::allowedIf($account->id() === $owner_id)
          ->addCacheableDependency($entity)
          ->addCacheableDependency($account);
    }
    // Unknown operation, deny access.
    return AccessResult::neutral()->addCacheableDependency($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL)
  {
    // Generally, we don't want users creating galleries directly,
    // as they are created on user registration. Deny by default.
    // You might change this based on requirements.
    return AccessResult::forbidden();
  }
}
