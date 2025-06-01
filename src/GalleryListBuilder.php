<?php

namespace Drupal\user_galleries;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Url;
use Drupal\user\Entity\User;

/**
 * Defines a class to build a listing of Gallery entities.
 *
 * @see \Drupal\user_galleries\Entity\Gallery
 */
class GalleryListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['id'] = $this->t('ID');
    $header['title'] = $this->t('Title');
    $header['owner'] = $this->t('Owner');
    $header['gallery_type'] = $this->t('Type');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\user_galleries\Entity\Gallery $entity */
    $row['id'] = $entity->id();
    $row['title'] = $entity->label();

    $owner = $entity->getOwner();
    if ($owner) {
      $row['owner']['data'] = [
        '#type' => 'link',
        '#title' => $owner->getDisplayName(),
        '#url' => $owner->toUrl(),
      ];
    } else {
      $row['owner'] = $this->t('N/A');
    }

    $row['gallery_type'] = ucfirst($entity->get('gallery_type')->value);
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    /** @var \Drupal\user_galleries\Entity\Gallery $entity */

    if ($entity->access('update') && $entity->getOwner()) {
        // Link to an admin route that uses GalleryManagementForm with user and gallery_type context
        $operations['edit'] = [
            'title' => $this->t('Edit'),
            'weight' => 10,
            'url' => Url::fromRoute('user_galleries.admin_manage_gallery', [
                'user' => $entity->getOwnerId(),
                'gallery_type' => $entity->get('gallery_type')->value,
            ]),
        ];
    }
    if ($entity->access('delete')) {
        // Uses the 'delete-form' link template from the entity annotation
        // which should point to entity.gallery.delete_form route
         $operations['delete'] = [
            'title' => $this->t('Delete'),
            'weight' => 100,
            'url' => $entity->toUrl('delete-form'),
        ];
    }

    return $operations;
  }

}
