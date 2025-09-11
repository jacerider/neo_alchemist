<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;

/**
 * Event that is fired when a component value is generated with an entity query.
 */
class ComponentValueEvent extends Event implements RefinableCacheableDependencyInterface {

  use RefinableCacheableDependencyTrait;

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
   * The entity.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface|null
   */
  public ?ContentEntityInterface $entity = NULL;

  /**
   * The delta.
   *
   * @var int|null
   */
  public ?int $delta;

  /**
   * The shape name.
   *
   * @var string|null
   */
  public ?string $shapeId;

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
   * @param \Drupal\Core\Entity\ContentEntityInterface|null $entity
   *   The entity.
   * @param int|null $delta
   *   The delta.
   * @param string|null $shapeId
   *   The child shape name.
   */
  public function __construct(ComponentShapePluginInterface $shape, mixed $value, ?ContentEntityInterface $entity = NULL, ?int $delta = NULL, ?string $shapeId = NULL) {
    $this->shape = $shape;
    $this->value = $value;
    $this->entity = $entity;
    $this->delta = $delta;
    $this->shapeId = $shapeId;
  }

  /**
   * Gets the ID.
   *
   * @return string
   *   The ID.
   */
  public function id(): string {
    return $this->shape->getComponent()->id() . ':' . ($this->shapeId ?? $this->shape->id());
  }

  /**
   * Gets the delta.
   *
   * @return int|null
   *   The delta.
   */
  public function getDelta(): ?int {
    return $this->delta;
  }

  /**
   * Gets the entity the component is associated with.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The entity.
   */
  public function getEntity(): ContentEntityInterface {
    return $this->entity ?? $this->shape->getEntity();
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
