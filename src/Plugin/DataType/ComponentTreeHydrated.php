<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\DataType;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Plugin\DataType\EntityAdapter;
use Drupal\Core\Render\RenderableInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\Attribute\DataType;
use Drupal\Core\TypedData\TypedData;

/**
 * @todo Do we need multiple variations of this? See \Drupal\datetime\DateTimeComputed for an example where there's *settings*
 * @phpstan-type JsonSchema array<string, mixed>
 */
#[DataType(
  id: "neo_component_tree_hydrated",
  label: new TranslatableMarkup("Hydrated component tree"),
  description: new TranslatableMarkup("Computed from tree structure + props values"),
)]
class ComponentTreeHydrated extends TypedData implements CacheableDependencyInterface, RenderableInterface {

  /**
   * {@inheritdoc}
   */
  public function toRenderable(): array {
    return [];
  }

  /**
   * Computes the cacheability of this computed property.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The cacheability of the computed value.
   */
  private function getCacheability(): CacheableMetadata {
    // @todo Once bundle-level defaults for `tree` + `props` are supported, this should also include cacheability of whatever config that is stored in.
    // @see \Drupal\experience_builder\Plugin\Field\FieldType\ComponentTreeItem::preSave()

    $root = $this->getRoot();
    if ($root instanceof EntityAdapter) {
      return CacheableMetadata::createFromObject($root->getEntity());
    }

    // This appears to be an ephemeral component tree, hence it is uncacheable.
    return (new CacheableMetadata())->setCacheMaxAge(0);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return $this->getCacheability()->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return $this->getCacheability()->getCacheContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return $this->getCacheability()->getCacheMaxAge();
  }

}
