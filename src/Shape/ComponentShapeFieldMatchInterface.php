<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Shape;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Decides whether an entity field can feed this prop.
 *
 * The predicate side of field mapping: given a field definition on the host
 * entity, may a site builder point this prop at it, and which of its
 * properties are on offer. `allowFieldDefinition()` is the gate — returning
 * FALSE stops the other questions being asked at all.
 *
 * Read by the matcher and the match locator, and by nothing else.
 *
 * @see \Drupal\neo_alchemist\Match\MatcherField
 * @see \Drupal\neo_alchemist\Match\FieldMatchLocator
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
 */
interface ComponentShapeFieldMatchInterface extends ComponentShapeIdentityInterface {

  /**
   * Checks if the field definition is supported by the shape.
   *
   * This differs from the support calls in that if it returns FALSE then
   * no other "supports" calls will be made.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition of the entity to match against.
   *
   * @return bool
   *   TRUE if the field definition is supported, FALSE otherwise.
   */
  public function allowFieldDefinition(FieldDefinitionInterface $entityFieldDefinition): bool;

  /**
   * Matches the field definition type with the entity field definition type.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition of the entity to match against.
   *
   * @return bool
   *   TRUE if the field definition types match, FALSE otherwise.
   */
  public function supportsFieldDefinition(FieldDefinitionInterface $entityFieldDefinition): bool;

  /**
   * Check if all field properties are supported.
   *
   * Returning TRUE means that all requirements of the shape are met by the
   * properties of this field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition of the entity to match against.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface[] $entityFieldProperties
   *   An array of field properties keyed by name.
   *
   * @return bool
   *   Returns TRUE if ALL field properties are supported, FALSE otherwise.
   */
  public function supportsFieldProperties(FieldDefinitionInterface $entityFieldDefinition, array $entityFieldProperties): bool;

  /**
   * Checks if the given entity field property is supported by the shape.
   *
   * This method determines if the shape can support the provided entity field
   * property. It first retrieves the shape's field properties and checks if
   * there is more than one property. If there is more than one property, it
   * returns FALSE, indicating that the shape cannot be matched by a single
   * property. If there is only one property, it iterates through the shape's
   * field properties and checks if the shape field property supports the given
   * entity field property.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition of the entity to match against.
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $entityFieldProperty
   *   The entity field property to check.
   *
   * @return bool
   *   TRUE if the shape supports the given entity field property, FALSE
   *   otherwise.
   */
  public function supportsFieldProperty(FieldDefinitionInterface $entityFieldDefinition, DataDefinitionInterface $entityFieldProperty): bool;

  /**
   * Provide custom matches for the field definition.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $entityFieldDefinition
   *   The field definition of the entity to match against.
   *
   * @return array
   *   An array of field keys => labels.
   *   For example, you can grant access to a link's option icon by returning
   *   the following:
   *     [
   *      'options:attributes~data-icon' => $this->t('Icon'),
   *     ]
   */
  public function getMatches(FieldDefinitionInterface $entityFieldDefinition);

}
