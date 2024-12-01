<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Plugin\Component;

/**
 * Provides an interface defining a component entity type.
 */
interface ComponentInterface extends ConfigEntityInterface {

  /**
   * Checks if the component is published.
   *
   * @return bool
   *   TRUE if the component is published, FALSE otherwise.
   */
  public function isPublished(): bool;

  /**
   * Gets the component plugin machine name.
   *
   * @return string
   *   The component plugin machine name.
   *
   * @see \Drupal\Core\Plugin\Component::$machineName
   */
  public function getComponentId(): string;

  /**
   * Gets the scope of the component.
   *
   * @return string
   *   The scope of the component.
   */
  public function getScope(): string;

  /**
   * Get the component.
   *
   * @return \Drupal\Core\Plugin\Component
   *   The component.
   */
  public function getComponent(): Component;

  /**
   * Get the component schema.
   *
   * @return mixed
   *   The schema.
   */
  public function getComponentSchema(): mixed;

  /**
   * Retrieves the settings for the component.
   *
   * @return array
   *   An array of settings. If no settings are defined, an empty array is
   *   returned.
   */
  public function getSettings(): array;

  /**
   * Retrieves a setting value by its key.
   *
   * @param string $key
   *   The key of the setting to retrieve.
   * @param mixed $default
   *   (optional) The default value to return if the setting is not found.
   *   Defaults to NULL.
   *
   * @return mixed
   *   The value of the setting if it exists, otherwise the default value.
   */
  public function getSetting(string $key, $default = NULL): mixed;

  /**
   * Sets a specific setting for the component.
   *
   * @param string $key
   *   The key of the setting to be set.
   * @param mixed $value
   *   The value to be set for the specified key.
   *
   * @return $this
   *   The current instance of the component for method chaining.
   */
  public function setSetting(string $key, $value): self;

  /**
   * Get the component definition.
   *
   * @return array
   *   The component definition.
   */
  public function getComponentDefinition(): array;

  /**
   * Gets the target entity type ID.
   *
   * @return string
   *   The target entity type ID.
   */
  public function getTargetEntityTypeId(): string;

  /**
   * Get the target entity type definition.
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface|null
   *   The target entity type definition.
   */
  public function getTargetEntityTypeDefinition(): ?EntityTypeInterface;

  /**
   * Gets the target entity bundle.
   *
   * @return string
   *   The target entity bundle.
   */
  public function getTargetEntityBundle(): string;

  /**
   * Retrieves the parent entity.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The parent entity.
   */
  public function getTargetEntity(): ContentEntityInterface;

  /**
   * Get prop shapes.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   The shapes.
   */
  public function getPropShapes(): array;

  /**
   * Get a prop shape.
   *
   * @param string $id
   *   The prop shape ID.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface|null
   *   The prop shape.
   */
  public function getPropShape(string $id): ?ComponentShapePluginInterface;

  /**
   * Get prop values.
   *
   * These values are ready for use with SDC rendering.
   *
   * @return array
   *   The prop values.
   */
  public function getPropValues(): array;

  /**
   * Converts the component entity to a renderable array.
   *
   * @return array
   *   A renderable array representing the component.
   */
  public function toRenderable();

}
