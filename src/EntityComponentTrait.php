<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * A trait for adding the module handler.
 */
trait EntityComponentTrait {

  /**
   * Retrieves the Neo component.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   * @param string $fieldName
   *   The field name.
   *
   * @return \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem|null
   *   The Neo component.
   */
  protected function getComponentFieldItem(ContentEntityInterface $entity, string $fieldName): ?ComponentTreeItem {
    $list = $entity->get($fieldName);
    if ($list->isEmpty()) {
      $list->appendItem([]);
    }
    $item = $list->first();
    assert($item instanceof ComponentTreeItem);
    return $item;
  }

}
