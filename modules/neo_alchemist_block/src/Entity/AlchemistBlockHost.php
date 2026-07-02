<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_block\Entity;

use Drupal\Core\Entity\ContentEntityBase;

/**
 * Defines the Alchemist block host entity type.
 *
 * This entity type is never persisted (it uses ContentEntityNullStorage, like
 * the core contact module's Message entity). It exists only so that the
 * Alchemist component tree of an Alchemist block config entity can be managed
 * and rendered through the existing neo_component_tree field stack: each
 * neo_alchemist_block config entity is a bundle of this type and provides a
 * runtime field_components bundle field.
 *
 * @see \Drupal\neo_alchemist_block\Entity\AlchemistBlock
 * @see \Drupal\neo_alchemist_block\Entity\AlchemistBlockFieldConfig
 * @see neo_alchemist_block_entity_bundle_field_info()
 *
 * @ContentEntityType(
 *   id = "neo_alchemist_block_host",
 *   label = @Translation("Alchemist Block"),
 *   label_singular = @Translation("alchemist block"),
 *   label_plural = @Translation("alchemist blocks"),
 *   label_count = @PluralTranslation(
 *     singular = "@count alchemist block",
 *     plural = "@count alchemist blocks",
 *   ),
 *   internal = TRUE,
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\ContentEntityNullStorage",
 *     "access" = "Drupal\neo_alchemist_block\AlchemistBlockHostAccessControlHandler",
 *     "form" = {
 *       "alchemist" = "Drupal\neo_alchemist\Form\InstanceComponentForm",
 *       "alchemist_edit" = "Drupal\neo_alchemist\Form\InstanceComponentForm",
 *       "alchemist_sort" = "Drupal\neo_alchemist\Form\InstanceComponentSortForm",
 *       "alchemist_delete" = "Drupal\neo_alchemist\Form\InstanceComponentDeleteForm",
 *     },
 *   },
 *   entity_keys = {
 *     "uuid" = "uuid",
 *     "bundle" = "type",
 *     "langcode" = "langcode",
 *   },
 *   bundle_entity_type = "neo_alchemist_block",
 * )
 */
class AlchemistBlockHost extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public function label() {
    $block = AlchemistBlock::load($this->bundle());
    return $block ? $block->label() : parent::label();
  }

}
