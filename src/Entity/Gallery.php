<?php

namespace Drupal\user_galleries\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\UserInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Gallery entity.
 *
 * @ContentEntityType(
 * id = "gallery",
 * label = @Translation("Gallery"),
 * base_table = "gallery",
 * entity_keys = {
 * "id" = "id",
 * "uuid" = "uuid",
 * "uid" = "uid",
 * "label" = "title",
 * "owner" = "uid",
 * },
 * handlers = {
 * "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 * "access" = "Drupal\user_galleries\GalleryAccessControlHandler",
 * "form" = {
 * "default" = "Drupal\user_galleries\Form\GalleryManagementForm",
 * "add" = "Drupal\user_galleries\Form\GalleryManagementForm",
 * "edit" = "Drupal\user_galleries\Form\GalleryManagementForm",
 * "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 * },
 * "list_builder" = "Drupal\user_galleries\GalleryListBuilder",
 * },
 * links = {
 * "edit-form" = "/admin/structure/user_galleries/gallery/{gallery}/edit",
 * "delete-form" = "/admin/structure/user_galleries/gallery/{gallery}/delete",
 * "collection" = "/admin/structure/user_galleries",
 * },
 * admin_permission = "administer user galleries",
 * )
 */
class Gallery extends ContentEntityBase implements ContentEntityInterface, EntityOwnerInterface
{

  use EntityOwnerTrait;

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type)
  {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setRequired(TRUE)
      ->setSettings([
        'max_length' => 255,
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Owner'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setDefaultValueCallback('Drupal\node\Entity\Node::getCurrentUserId')
      ->setTranslatable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'author',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['gallery_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Gallery Type'))
      ->setSettings([
        'allowed_values' => [
          'public' => 'Public',
          'private' => 'Private',
        ],
      ])
      ->setRequired(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -4,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['images'] = BaseFieldDefinition::create('image')
      ->setLabel(t('Images'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSettings([
        'file_directory' => 'galleries/[date:custom:Y]-[date:custom:m]',
        'file_extensions' => 'png jpg jpeg gif',
        'max_filesize' => '', // Set a limit if needed
        'alt_field' => TRUE,
        'title_field' => TRUE,
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'image',
        'weight' => 1,
      ])
      ->setDisplayOptions('form', [
        'type' => 'image_image',
        'weight' => 1,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['allowed_users'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Allowed Users'))
      ->setDescription(t('Users who can view this gallery (if private).'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 10,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', FALSE);

    return $fields;
  }

  // /**
  //  * {@inheritdoc}
  //  */
  // public function preSave(\Drupal\Core\Entity\EntityStorageInterface $storage)
  // {
  //   parent::preSave($storage);

  //   // Set the file storage based on gallery type.
  //   if ($this->get('gallery_type')->value === 'private') {
  //     $this->get('images')->setSetting('uri_scheme', 'private');
  //   } else {
  //     $this->get('images')->setSetting('uri_scheme', 'public');
  //   }
  // }
}
