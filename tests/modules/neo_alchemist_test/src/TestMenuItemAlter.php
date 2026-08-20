<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;

/**
 * Controllable behaviour for hook_neo_alchemist_menu_value_item_alter().
 *
 * The hook implementation in neo_alchemist_test.module delegates here, and is
 * inert until a test switches a mode on. Keeping the behaviour in a class
 * (rather than a state-flag soup inside the .module file) keeps the hook body
 * to one line and the modes readable.
 */
final class TestMenuItemAlter {

  /**
   * The cache tag ::MODE_CACHEABILITY adds via the shape.
   */
  public const CACHE_TAG = 'na_menu_item_sentinel';

  /**
   * Do nothing (the default).
   */
  public const MODE_INERT = 'inert';

  /**
   * Add an extra key to every entry.
   */
  public const MODE_ENRICH = 'enrich';

  /**
   * Drop any entry whose title is ::$vetoTitle.
   */
  public const MODE_VETO = 'veto';

  /**
   * Add a cacheable dependency through the shape.
   */
  public const MODE_CACHEABILITY = 'cacheability';

  /**
   * The active mode.
   *
   * @var string
   */
  public static string $mode = self::MODE_INERT;

  /**
   * The title MODE_VETO drops.
   *
   * @var string
   */
  public static string $vetoTitle = '';

  /**
   * Titles the hook was invoked for, in invocation order.
   *
   * @var string[]
   */
  public static array $seenTitles = [];

  /**
   * Resets to the inert default.
   */
  public static function reset(): void {
    static::$mode = self::MODE_INERT;
    static::$vetoTitle = '';
    static::$seenTitles = [];
  }

  /**
   * Applies the active mode to one menu entry.
   *
   * @param array|null $entry
   *   The entry being altered, by reference. Setting it to NULL drops the item.
   * @param array $item
   *   The built menu tree item the entry came from.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The shape resolving the value.
   */
  public static function apply(?array &$entry, array $item, ComponentShapePluginInterface $shape): void {
    static::$seenTitles[] = (string) ($entry['title'] ?? '');

    switch (static::$mode) {
      case self::MODE_ENRICH:
        // An extra key must survive into the component prop verbatim.
        $entry['badge'] = 'BADGE:' . ($entry['title'] ?? '');
        break;

      case self::MODE_VETO:
        if (($entry['title'] ?? NULL) === static::$vetoTitle) {
          $entry = NULL;
        }
        break;

      case self::MODE_CACHEABILITY:
        $shape->addCacheableDependency(
          (new CacheableMetadata())->addCacheTags([self::CACHE_TAG])
        );
        break;
    }
  }

}
