<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Controllable behaviour for the entity component fields alter hook.
 *
 * The hook implementation in neo_alchemist_test.module delegates here, and is
 * inert until a test names the fields to keep — so simply enabling the fixture
 * module cannot narrow any other test's field set.
 *
 * Models what neo_alchemist_taxonomy does: only one of an entity's component
 * tree fields applies to any given entity.
 */
final class TestEntityComponentFieldsAlter {

  /**
   * Field names to keep, or NULL to leave the set untouched.
   *
   * @var string[]|null
   */
  public static ?array $keep = NULL;

  /**
   * Resets to the inert default.
   */
  public static function reset(): void {
    static::$keep = NULL;
  }

  /**
   * Narrows the field set to ::$keep.
   *
   * @param \Drupal\neo_alchemist\ComponentFieldConfigInterface[] $fieldDefinitions
   *   The applicable field definitions, by reference.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity the definitions were gathered for.
   */
  public static function apply(array &$fieldDefinitions, ContentEntityInterface $entity): void {
    if (static::$keep === NULL) {
      return;
    }
    foreach (array_keys($fieldDefinitions) as $fieldName) {
      if (!in_array($fieldName, static::$keep, TRUE)) {
        unset($fieldDefinitions[$fieldName]);
      }
    }
  }

}
