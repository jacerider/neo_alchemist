<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * Defines a component slot.
 */
class ComponentSlot implements ComponentSlotInterface {

  /**
   * The component.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $component;

  /**
   * The slot name.
   *
   * @var string
   */
  protected $name;

  /**
   * The slot schema.
   *
   * @var array
   */
  protected $schema;

  /**
   * The slot configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * Constructs a new ComponentSlot object.
   */
  public function __construct(ComponentInterface $component, string $name, array $schema, array $config = []) {
    $this->component = $component;
    $this->name = $name;
    $this->schema = $schema;
    $this->config = $config;
  }

  /**
   * {@inheritDoc}
   */
  public function getComponent(): ComponentInterface {
    return $this->component;
  }

  /**
   * {@inheritDoc}
   */
  public function getName(): string {
    return $this->name;
  }

  /**
   * {@inheritDoc}
   */
  public function getTitle(): string {
    return $this->schema['title'] ?? 'Unnamed Slot';
  }

  /**
   * {@inheritDoc}
   */
  public function getDescription(): string {
    return $this->schema['description'] ?? 'Unnamed Slot';
  }

}
