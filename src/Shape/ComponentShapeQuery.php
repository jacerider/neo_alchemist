<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Shape;

use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList;

/**
 * Provides a query interface for component shapes.
 */
class ComponentShapeQuery {

  /**
   * The field that contains the component tree.
   *
   * @var \Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList
   */
  protected NeoComponentTreeList $field;

  /**
   * The reference to filter components by.
   *
   * @var string
   */
  protected string $ref = '';

  /**
   * Flag to indicate whether to filter out empty components.
   *
   * @var bool
   */
  protected bool $empty = TRUE;

  /**
   * The format to filter components by.
   *
   * @var string
   */
  protected string $format = '';

  /**
   * The limit for the number of components to return.
   *
   * @var int
   */
  protected int $limit = 0;

  /**
   * Constructs a new ComponentShapeQuery object.
   */
  public function __construct(NeoComponentTreeList $field) {
    $this->field = $field;
  }

  /**
   * Filters components by a specific reference.
   *
   * @param string $ref
   *   The reference to filter components by.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function filterByRef(string $ref): self {
    $this->ref = $ref;
    return $this;
  }

  /**
   * Filters out empty components.
   *
   * @param bool $empty
   *   If TRUE, only non-empty components are returned. If FALSE, empty
   *   components are included.
   *
   * @return $this
   */
  public function filterNonEmpty(bool $empty = TRUE): self {
    $this->empty = $empty;
    return $this;
  }

  /**
   * Filters components by a specific format.
   *
   * @param string $format
   *   The format to filter components by.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function filterByFormat(string $format): self {
    $this->format = $format;
    return $this;
  }

  /**
   * Sets the limit for the number of components to return.
   *
   * @param int $limit
   *   The maximum number of components to return. Use 0 for no limit.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function limit(int $limit): self {
    $this->limit = $limit;
    return $this;
  }

  /**
   * Execute the query and return the matched shapes.
   *
   * In reading order: every published component of the tree, a component's
   * own props before its children's, and an array prop's items one by one —
   * the item shapes that hold values, not the array's template shape.
   *
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface[]
   *   An array of component shapes.
   */
  public function execute() {
    /** @var \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface[] $shapes */
    $shapes = [];
    $item = $this->field->first();
    if ($item instanceof ComponentTreeItem) {
      $this->collect($item, ComponentTreeStructure::ROOT_UUID, $shapes, 0);
    }
    return $shapes;
  }

  /**
   * Collects the matching shapes of one parent's components, then theirs.
   *
   * @return bool
   *   TRUE once the limit is reached.
   */
  protected function collect(ComponentTreeItem $item, string $parentUuid, array &$shapes, int $depth): bool {
    if ($depth > 20) {
      return FALSE;
    }
    foreach ($item->getComponents($parentUuid) as $uuid => $component) {
      if (!$component->isPublished()) {
        continue;
      }
      foreach ($component->getPropShapesAll(NULL, TRUE) as $shape) {
        // Skip shapes that do not match the reference if a ref is set.
        if ($this->ref && $shape->getRef() !== $this->ref) {
          continue;
        }
        // Skip empty shapes if the empty filter is set.
        if ($this->empty && $shape->isEmpty()) {
          continue;
        }
        // Skip shapes that do not match the format.
        if ($this->format && $shape->getFormat() !== $this->format) {
          continue;
        }
        $shapes[] = $shape;
        if ($this->limit > 0 && count($shapes) >= $this->limit) {
          return TRUE;
        }
      }
      if ($this->collect($item, (string) $uuid, $shapes, $depth + 1)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns the first component shape in the field.
   *
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface|null
   *   The first component shape, or NULL if no shapes are found.
   */
  public function first() {
    $this->limit(1);
    $shapes = $this->execute();
    if (empty($shapes)) {
      return NULL;
    }
    return reset($shapes);
  }

}
