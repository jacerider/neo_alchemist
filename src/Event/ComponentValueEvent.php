<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;

/**
 * Event that is fired when a component value is generated with an entity query.
 */
class ComponentValueEvent extends Event implements RefinableCacheableDependencyInterface {

  use RefinableCacheableDependencyTrait;

  const EVENT_NAME = 'neo_component_value';

  /**
   * The shape.
   *
   * @var \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
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
   * The pager element a subscriber claimed, or NULL when none paginated.
   *
   * @var int|null
   */
  public ?int $pagerElement = NULL;

  /**
   * Constructs the object.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
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
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
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
   * Declares that this subscriber paginated its own query.
   *
   * A subscriber that builds a paged query — $query->pager($limit, $element),
   * or any other call that reaches PagerManager::createPager() — must say so
   * here for a pager slot on the same component to render. Without it the slot
   * finds no pager context and stays empty, because a slot that renders a bare
   * ['#type' => 'pager'] would render pager element 0 no matter who created
   * it, which is how a stranded slot ends up showing some other list's pager.
   *
   * Pass the element explicitly to $query->pager() rather than letting it
   * default to NULL: core then assigns getMaxPagerElementId() + 1, which is
   * only 0 when nothing else on the page paginated first.
   *
   * The caller is still responsible for the matching cache context —
   * $event->addCacheContexts(['url.query_args.pagers:' . $element]) — since
   * the rows themselves vary by page whether or not a pager is rendered.
   *
   * @param int $element
   *   The pager element the query claimed.
   *
   * @return self
   *   The current instance of the class.
   */
  public function setPagerElement(int $element): self {
    $this->pagerElement = $element;
    return $this;
  }

  /**
   * Gets the pager element this subscriber claimed, if any.
   *
   * @return int|null
   *   The pager element, or NULL when no subscriber paginated.
   */
  public function getPagerElement(): ?int {
    return $this->pagerElement;
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
