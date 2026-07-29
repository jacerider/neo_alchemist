<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test;

/**
 * Static call recorder for the recording ComponentValue fixture plugins.
 *
 * A named class rather than closures or anonymous classes (phpcbf mangles
 * the latter — see TESTING.md). Tests call ::reset() in setUp() and assert
 * against ::$calls after resolving values.
 */
final class TestValueCallLog {

  /**
   * Recorded calls, each as "<pluginId>:<op>" in call order.
   *
   * @var string[]
   */
  public static array $calls = [];

  /**
   * Records a plugin hook invocation.
   *
   * @param string $pluginId
   *   The value plugin id.
   * @param string $op
   *   The hook name, e.g. 'provideDefaultValue'.
   */
  public static function record(string $pluginId, string $op): void {
    static::$calls[] = $pluginId . ':' . $op;
  }

  /**
   * Clears the log.
   */
  public static function reset(): void {
    static::$calls = [];
  }

  /**
   * Returns the logged entries for one hook, in call order.
   *
   * @param string $op
   *   The hook name.
   *
   * @return string[]
   *   The matching "<pluginId>:<op>" entries.
   */
  public static function callsFor(string $op): array {
    return array_values(array_filter(
      static::$calls,
      static fn (string $call): bool => str_ends_with($call, ':' . $op),
    ));
  }

}
