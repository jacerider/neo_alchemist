<?php

declare(strict_types = 1);

namespace Drupal\neo_alchemist\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;

/**
 * Event that is fired when a component value is generated with an entity query.
 */
class ComponentValueEvent extends Event {

  const EVENT_NAME = 'neo_component_value';

  /**
   * The shape.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface
   */
  public ComponentShapePluginInterface $shape;

  /**
   * The value.
   *
   * @var mixed
   */
  public mixed $value;

  /**
   * The default value.
   *
   * @var mixed
   */
  public mixed $defaultValue;

  /**
   * Flag to continue processing.
   *
   * @var bool
   */
  public bool $continueProcessing = TRUE;

  /**
   * Constructs the object.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape.
   * @param mixed $value
   *   The value.
   * @param mixed $defaultValue
   *   The default value.
   */
  public function __construct(ComponentShapePluginInterface $shape, mixed $value, mixed $defaultValue) {
    $this->shape = $shape;
    $this->value = $value;
    $this->defaultValue = $defaultValue;
  }

  /**
   * Gets the ID.
   *
   * @return string
   *   The ID.
   */
  public function id(): string {
    return $this->shape->getComponent()->id() . ':' . $this->shape->id();
  }

  /**
   * Gets the entity query.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The entity query.
   */
  public function getEntity(): ContentEntityInterface {
    return $this->shape->getEntity();
  }

  /**
   * Gets the shape.
   *
   * @return \Drupal\neo_alchemist\ComponentShapePluginInterface
   *   The shape.
   */
  public function getShape() {
    return $this->shape;
  }

  /**
   * Gets the value.
   *
   * @return mixed
   *   The value.
   */
  public function getValue() {
    return $this->value;
  }

  /**
   * Sets the value.
   *
   * @param mixed $value
   *   The value.
   */
  public function setValue(mixed $value) {
    $this->value = $value;
  }

  /**
   * Gets the default value.
   *
   * @return mixed
   *   The default value.
   */
  public function getDefaultValue() {
    return $this->defaultValue;
  }

  /**
   * Stops the processing by setting the continue flag to FALSE.
   *
   * This will prevent any following value providers from being processed.
   *
   * @return self
   *   The current instance of the class.
   */
  public function stopFurtherProcessing(): self {
    $this->continueProcessing = FALSE;
    return $this;
  }

  /**
   * Determines if following processors should be allowed to process.
   *
   * @return bool
   *   TRUE if processing should continue, FALSE otherwise.
   */
  public function shouldContinueProcessing(): bool {
    return $this->continueProcessing === TRUE;
  }

}
