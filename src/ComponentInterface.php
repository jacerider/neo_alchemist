<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Plugin\Component;
use Drupal\neo_alchemist\PropSource\StaticPropSource;

/**
 * Provides an interface defining a component entity type.
 */
interface ComponentInterface extends ConfigEntityInterface {

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
  public function getComponent(): Component;

  /**
   * Get the component schema.
   *
   * @return mixed
   *   The schema.
   */
  public function getComponentSchema(): mixed;

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
  public function getTargetEntityTypeDefinition(): EntityTypeInterface|null;

  /**
   * Gets the target entity bundle.
   *
   * @return string
   *   The target entity bundle.
   */
  public function getTargetEntityBundle(): string;

  /**
   * Get the default static source for a prop.
   *
   * @param string $prop_name
   *   The prop name.
   *
   * @return \Drupal\neo_alchemist\PropSource\StaticPropSource|null
   *   The default static source for the prop, or NULL if none.
   */
  public function getDefaultStaticPropSource(string $prop_name): ?StaticPropSource;

  /**
   * Get the defaults for a component plugin.
   *
   * @param \Drupal\Core\Plugin\Component $component_plugin
   *   The component plugin.
   *
   * @return array
   *   The defaults for the component plugin.
   */
  public static function getDefaultsForComponentPlugin(Component $component_plugin): array;

}
