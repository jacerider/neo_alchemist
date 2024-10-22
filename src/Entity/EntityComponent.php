<?php

declare(strict_types = 1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Url;

/**
 * Decorator for an Alchemist-enabled entity.
 */
class EntityComponent {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    private readonly ContentEntityInterface $entity,
    private readonly string $fieldName
  ) {
  }

  /**
   * Create a new event order.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The field name.
   *
   * @return EntityComponent
   *   The entity component.
   */
  public static function createFromEntity(ContentEntityInterface $entity, string $field_name): EntityComponent {
    if (substr($field_name, 0, 6) !== 'field_') {
      $field_name = static::getFieldnameFromKey($field_name);
    }
    return new static($entity, $field_name);
  }

  public static function getKeyFromFieldname(string $field_name): string {
    return str_replace('_', '-', substr($field_name, 6));
  }

  public static function getFieldnameFromKey(string $key): string {
    return 'field_' . str_replace('-', '_', $key);
  }

  public function toUrl(): Url {
    return Url::fromRoute("entity.{$this->entity->getEntityTypeId()}.alchemist.{$this->fieldName}", [
      $this->entity->getEntityTypeId() => $this->entity->id(),
    ]);
  }

}
