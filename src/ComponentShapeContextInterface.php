<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * The givens: what the shape was built from and what it is operating inside.
 *
 * Everything here was handed to the shape from outside rather than worked out
 * by it — the component that owns the prop, the entity it is rendering
 * against, the entity type and bundle the component was configured for (not
 * the host entity's, because the host is generated even when the component
 * names none), whether any of it is new or mid-rebuild, and the stored
 * settings the shape was constructed with.
 *
 * `getSettings()` is here for that reason and not because it is a kind of
 * surroundings: it is the configuration blob the plugin manager passed in,
 * which seeds the active, expanded, editable, required and plugin state before
 * any role reads them back. Its one caller outside the shape family asks for it
 * alongside `getSchema()` and `getComponent()` in order to build a second shape
 * like this one.
 *
 * `getComponent()` is the single most-called shape method in the ComponentValue
 * family, which is why this is a role rather than a corner of another one.
 *
 * @see \Drupal\neo_alchemist\ComponentShapePluginManager::getInstance()
 * @see \Drupal\neo_alchemist\ComponentShapePluginInterface
 */
interface ComponentShapeContextInterface extends ComponentShapeIdentityInterface {

  /**
   * Get the component.
   *
   * @return \Drupal\neo_alchemist\ComponentInterface
   *   The component.
   */
  public function getComponent(): ComponentInterface;

  /**
   * Retrieves the content entity associated with this plugin.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The content entity.
   */
  public function getEntity(): ContentEntityInterface;

  /**
   * Get the entity type.
   *
   * This is the entity type id set on the component. It is not the entity type
   * of the host entity as that entity is dynamically generated even if there is
   * no entity type set on the component.
   *
   * @return string
   *   The entity type.
   */
  public function getTargetEntityType(): ?string;

  /**
   * Get the entity bundle.
   *
   * This is the bundle id set on the component. It is not the bundle
   * of the host entity as that entity is dynamically generated even if there is
   * no bundle set on the component.
   *
   * @return string
   *   The entity bundle.
   */
  public function getTargetEntityBundle(): ?string;

  /**
   * Gets the scope of the component shape.
   *
   * @return string
   *   The scope of the component shape.
   */
  public function getScope(): string;

  /**
   * Checks if the component is new.
   *
   * @return bool
   *   TRUE if the component is new, FALSE otherwise.
   */
  public function isNew(): bool;

  /**
   * Checks if the component is rebuilding.
   *
   * Will be true if the component is being rebuilt without being saved.
   *
   * @return bool
   *   TRUE if the component is rebuilding, FALSE otherwise.
   */
  public function isRebuilding();

  /**
   * Returns the initial settings for the shape.
   *
   * @return array
   *   An associative array of initial settings for the shape.
   */
  public function getSettings(): array;

}
