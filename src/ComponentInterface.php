<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Plugin\Component;
use Drupal\Core\Render\RenderableInterface;

/**
 * Provides an interface defining a component entity type.
 */
interface ComponentInterface extends ConfigEntityInterface, RenderableInterface, CacheableResponseInterface {

  /**
   * Get the component description.
   *
   * @return string
   *   The description.
   */
  public function getDescription(): string;

  /**
   * Get the component expression.
   *
   * @return string
   *   The expression.
   */
  public function getExpression(): string;

  /**
   * Generates a combined expression string from the component's shapes.
   *
   * This method retrieves all shapes associated with the component, extracts
   * their individual expressions, sorts them, and then concatenates them into
   * a single string separated by the '~' character.
   *
   * @return string
   *   The combined expression string.
   */
  public function generateExpression(): string;

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
   * Get the component.
   *
   * @return \Drupal\Core\Plugin\Component
   *   The component.
   */
  public function getComponent(): ?Component;

  /**
   * Get the component schema.
   *
   * @return array
   *   The schema.
   */
  public function getComponentSchema(): ?array;

  /**
   * Get the component slots.
   *
   * @return array
   *   The slots.
   */
  public function getComponentSlots(): array;

  /**
   * Gets the thumbnail id.
   *
   * @return string|null
   *   The thumbnail.
   */
  public function getThumbnailId(): ?string;

  /**
   * Retrieves the default thumbnail path for the component.
   *
   * @return string|null
   *   The path to the default thumbnail, or NULL if not available.
   */
  public function getDefaultThumbnail(): ?string;

  /**
   * Retrieves the thumbnail URL for the component.
   *
   * This method attempts to load a thumbnail image associated with the
   * component. If a thumbnail image is found, it generates and returns the
   * absolute URL of the image. If no thumbnail image is found, it returns the
   * URL of a default thumbnail image.
   *
   * @return string|null
   *   The absolute URL of the thumbnail image, or the URL of the default
   *   thumbnail image.
   */
  public function getThumbnail(): ?string;

  /**
   * Gets the scope of the component.
   *
   * @return string
   *   The scope of the component.
   */
  public function getScope(): string;

  /**
   * Sets the preview status of the component.
   *
   * @param bool $preview
   *   The preview status to set.
   *
   * @return $this
   *   The current instance of the component.
   */
  public function setPreview(bool $preview): self;

  /**
   * Checks if the component is in preview mode.
   *
   * @return bool
   *   TRUE if the component is in preview mode, FALSE otherwise.
   */
  public function isPreview(): bool;

  /**
   * Set the component as rebuilding.
   *
   * @param bool $rebuilding
   *   TRUE if the component is rebuilding, FALSE otherwise.
   *
   * @return $this
   */
  public function setRebuilding(bool $rebuilding): self;

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
  public function getTargetEntityTypeId(): ?string;

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
  public function getTargetEntityBundle(): ?string;

  /**
   * Retrieves the parent entity.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The parent entity.
   */
  public function getTargetEntity(): ContentEntityInterface;

  /**
   * Sets the target preview entity for the component.
   *
   * This method sets the preview entity for the component if the component is
   * not new and the target entity type ID is available. It loads the entity
   * using the provided entity ID and stores the entity ID in the state system.
   *
   * @param string $entityId
   *   The ID of the entity to be set as the preview entity.
   *
   * @return bool
   *   TRUE if the preview entity was successfully set, FALSE otherwise.
   */
  public function setTargetPreviewEntity(string $entityId): bool;

  /**
   * Retrieves the target preview entity associated with this component.
   *
   * This method checks the state storage for a preview entity ID associated
   * with this component and loads the corresponding entity if it exists.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The target preview entity if it exists, or NULL otherwise.
   */
  public function getTargetPreviewEntity(): ?ContentEntityInterface;

  /**
   * Check if the component has preview styles.
   *
   * @return bool
   *   TRUE if the component has preview styles, FALSE otherwise.
   */
  public function hasPreviewStyles(): bool;

  /**
   * Get the preview styles for the component.
   *
   * @return array
   *   An associative array of preview styles.
   */
  public function getPreviewStyles(): array;

  /**
   * Set the preview style for a specific shape ID.
   *
   * @param string $shapeId
   *   The shape ID.
   * @param string $shapeValue
   *   The shape value.
   *
   * @return $this
   *   The current object for method chaining.
   */
  public function setPreviewStyle(string $shapeId, string $shapeValue): self;

  /**
   * Get the preview style for a specific shape ID.
   *
   * @param string $shapeId
   *   The shape ID.
   *
   * @return string|null
   *   The preview style for the shape ID, or NULL if not set.
   */
  public function getPreviewStyle(string $shapeId): ?string;

  /**
   * Delete the preview style for this component.
   *
   * @return $this
   *   The current object for method chaining.
   */
  public function resetPreviewStyle(): self;

  /**
   * Load prop shapes.
   *
   * @param array $schema
   *   The schema.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface[]
   *   The shapes.
   */
  public function loadPropShapes(array $schema): array;

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
   * Retrieves all property shape settings.
   *
   * This method returns an array of property shape settings from the
   * component's settings. If no settings are found, an empty array is returned.
   *
   * @return array
   *   An array of property shape settings.
   */
  public function getAllPropShapeSettings(): array;

  /**
   * Retrieves the shape settings for a given property ID.
   *
   * @param string $propId
   *   The ID of the property for which to get the shape settings.
   *
   * @return array
   *   An array of shape settings for the specified property ID. If the property
   *   ID does not exist, an empty array is returned.
   */
  public function getPropShapeSettings(string $propId): array;

  /**
   * Sets the shape settings for a component.
   *
   * This method configures the shape settings for a component by extracting
   * various properties and plugin configurations from the provided shape
   * plugin interface. It handles nested shapes and their expanded states,
   * ensuring that only the relevant settings are retained.
   *
   * @param \Drupal\neo_alchemist\Plugin\ComponentShapePluginInterface $shape
   *   The shape plugin interface from which to extract settings.
   *
   * @return $this
   *   The current instance of the component with updated shape settings.
   */
  public function setPropShapeSettings(ComponentShapePluginInterface $shape): self;

  /**
   * Retrieves a flat array of all shapes keyed by their nested ID.
   *
   * This method iterates through the provided shapes and collects them into an
   * associative array. If a shape has child shapes, it collects those as well.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface[] $shapes
   *   An array of shape objects to process.
   *
   * @return array
   *   An associative array of all shapes, with keys being the shape's nested ID
   *   (and optionally the reference) and values being the shape objects.
   */
  public function getAllPropShapes(array $shapes): array;

  /**
   * Retrieves the slots for the component.
   *
   * This method initializes the slots array if it is not already set. It uses
   * the neo_component.slot.factory service to create slot instances based on
   * the component's slot schema. The slots are then stored in the $slots
   * property and returned.
   *
   * @return \Drupal\neo_alchemist\ComponentSlotInterface[]
   *   An array of slots for the component.
   */
  public function getSlots(): array;

  /**
   * Retrieves the slot with the given name.
   *
   * @param string $slotName
   *   The name of the slot to retrieve.
   *
   * @return \Drupal\neo_alchemist\ComponentSlotInterface|null
   *   The slot object if found, or NULL if the slot does not exist.
   */
  public function getSlot(string $slotName): ?ComponentSlotInterface;

  /**
   * Retrieves the slot settings for a specific slot.
   *
   * @param \Drupal\neo_alchemist\ComponentSlotInterface $slot
   *   The slot for which the settings are being retrieved.
   *
   * @return array
   *   An associative array of settings for the specified slot.
   */
  public function getSlotSettings(ComponentSlotInterface $slot): array;

  /**
   * Sets the settings for a specific slot.
   *
   * @param \Drupal\neo_alchemist\ComponentSlotInterface $slot
   *   The slot for which the settings are being set.
   * @param array $settings
   *   An associative array of settings to be applied to the slot.
   *
   * @return $this
   *   The current instance of the Component entity.
   */
  public function setSlotSettings(ComponentSlotInterface $slot, array $settings): self;

  /**
   * Sets a filter for the component.
   *
   * This method assigns a filter to the component. If the filter is new, a UUID
   * is generated for it; otherwise, the existing UUID of the filter is used.
   * The filter is then converted to an array and stored in the component's
   * settings.
   *
   * @param \Drupal\neo_alchemist\ComponentFilterInterface $filter
   *   The filter to be set for the component.
   *
   * @return \Drupal\neo_alchemist\ComponentFilterInterface
   *   The filter.
   */
  public function setFilter(ComponentFilterInterface $filter): ComponentFilterInterface;

  /**
   * Retrieves a filter by its UUID.
   *
   * @param string $uuid
   *   The UUID of the filter to retrieve.
   *
   * @return \Drupal\neo_alchemist\ComponentFilterInterface|null
   *   The filter associated with the given UUID, or NULL if no filter is found.
   */
  public function getFilter(string $uuid): ?ComponentFilterInterface;

  /**
   * Deletes a filter from the settings based on the provided UUID.
   *
   * @param string $uuid
   *   The UUID of the filter to be deleted.
   *
   * @return self
   *   The current instance of the Component entity.
   */
  public function deleteFilter(string $uuid): self;

  /**
   * Retrieves the filters for the component.
   *
   * This method initializes the filters if they are not already set. It checks
   * if there are any filters defined in the settings and uses the neo_component
   * filter factory service to create filter instances based on the settings.
   *
   * @return \Drupal\neo_alchemist\ComponentFilterInterface[]
   *   An array of filters for the component.
   */
  public function getFilters(): array;

  /**
   * Sets a access for the component.
   *
   * This method assigns a access to the component. If the access is new, a UUID
   * is generated for it; otherwise, the existing UUID of the access is used.
   * The access is then converted to an array and stored in the component's
   * settings.
   *
   * @param \Drupal\neo_alchemist\ComponentAccessInterface $access
   *   The access to be set for the component.
   *
   * @return \Drupal\neo_alchemist\ComponentAccessInterface
   *   The access.
   */
  public function setAccess(ComponentAccessInterface $access): ComponentAccessInterface;

  /**
   * Retrieves a access by its UUID.
   *
   * @param string $uuid
   *   The UUID of the access to retrieve.
   *
   * @return \Drupal\neo_alchemist\ComponentAccessInterface|null
   *   The access associated with the given UUID, or NULL if no access is found.
   */
  public function getAccess(string $uuid): ?ComponentAccessInterface;

  /**
   * Deletes a access from the settings based on the provided UUID.
   *
   * @param string $uuid
   *   The UUID of the access to be deleted.
   *
   * @return self
   *   The current instance of the Component entity.
   */
  public function deleteAccess(string $uuid): self;

  /**
   * Retrieves the accesss for the component.
   *
   * This method initializes the accesss if they are not already set. It checks
   * if there are any accesss defined in the settings and uses the neo_component
   * access factory service to create access instances based on the settings.
   *
   * @return \Drupal\neo_alchemist\ComponentAccessInterface[]
   *   An array of accesss for the component.
   */
  public function getAccessInstances(): array;

}
