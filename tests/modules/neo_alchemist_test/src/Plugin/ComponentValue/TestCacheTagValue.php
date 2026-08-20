<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Plugin\ComponentValue;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;

/**
 * A modifiers-group plugin that adds a sentinel cache tag while modifying.
 *
 * Exists to prove the cacheability merge ordering: dependencies a value
 * plugin adds during value computation must land in the component's
 * cacheable metadata (Component::getPropValues() merges the shape metadata
 * only AFTER getPropValue()) and survive into toRenderable()'s #cache.
 */
#[ComponentValue(
  id: 'na_cache_tag_value',
  label: new TranslatableMarkup('Test cache tag value'),
  description: new TranslatableMarkup('Adds the na_value_sentinel cache tag during modifyValue().'),
  group: 'modifiers',
  prop_types: [
    ComponentShapePluginInterface::STRING,
  ],
  weight: 20,
)]
final class TestCacheTagValue extends ComponentValuePluginBase {

  /**
   * The sentinel cache tag this plugin adds.
   */
  public const CACHE_TAG = 'na_value_sentinel';

  /**
   * {@inheritdoc}
   */
  public function modifyValue(mixed $value): mixed {
    $this->getShape()->addCacheableDependency(
      (new CacheableMetadata())->addCacheTags([static::CACHE_TAG])
    );
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    return TRUE;
  }

}
