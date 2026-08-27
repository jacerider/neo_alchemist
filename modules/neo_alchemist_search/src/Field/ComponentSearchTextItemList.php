<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_search\Field;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\neo_alchemist_search\ComponentTextExtractor;

/**
 * Exposes an entity's Alchemist text as an ordinary field.
 *
 * Being a field rather than a search-specific plugin is what makes this usable:
 * Search API's entity datasource picks up computed fields automatically, so the
 * text appears in its field list with nothing else to configure, and anything
 * else that reads fields can use it too.
 *
 * One value per text run rather than one joined string. Search backends put a
 * position gap between values, which stops a phrase query matching across the
 * boundary between two unrelated components — joining with a newline would not,
 * because tokenisers treat it as ordinary whitespace and the tail of one
 * heading would form a phrase with the head of the next.
 */
final class ComponentSearchTextItemList extends FieldItemList {

  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue(): void {
    $entity = $this->getEntity();
    if (!$entity instanceof ContentEntityInterface) {
      return;
    }
    // Computed lazily rather than on entity load: most requests never look at
    // this field, and walking every component tree on the page would be a real
    // cost to impose on them.
    foreach ($this->extractor()->extract($entity) as $delta => $text) {
      $this->list[$delta] = $this->createItem($delta, $text);
    }
  }

  /**
   * The extractor service.
   */
  private function extractor(): ComponentTextExtractor {
    return \Drupal::service('neo_alchemist_search.text_extractor');
  }

}
