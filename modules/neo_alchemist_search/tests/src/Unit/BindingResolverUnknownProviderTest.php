<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist_search\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityValue;
use Drupal\neo_alchemist\Value\ComponentValuePluginManagerInterface;
use Drupal\neo_alchemist_search\Binding\BindingResolver;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers a component naming a value plugin that declares no field source.
 *
 * A unit test rather than a kernel one because the fixture is configuration for
 * a plugin that does not exist, which no schema can describe — writing it as
 * real config would mean switching off schema checking.
 *
 * The behaviour matters because contrib and custom code add value plugins at
 * any time. The resolver never interprets a plugin's settings itself: the
 * settings key that means "read this field" in one plugin means something else
 * in the next, so reading one uninvited could put a machine name or a
 * serialised blob into an index. A plugin that has not declared itself a field
 * source contributes nothing, and is counted so the report can show it.
 */
#[Group('neo_alchemist_search')]
final class BindingResolverUnknownProviderTest extends UnitTestCase {

  /**
   * A plugin that declares no field source contributes nothing, and is counted.
   */
  public function testUndeclaredProviderIsCountedNotGuessed(): void {
    $set = $this->resolveWithSettings([
      'props' => [
        'body' => [
          'active' => TRUE,
          'plugins' => [
            'body' => [
              // `field` is the key the entity provider uses. Reading it here
              // would be a guess that happens to look right.
              'some_contrib_provider' => [
                'id' => 'some_contrib_provider',
                'settings' => ['field' => 'field_body'],
              ],
            ],
          ],
        ],
      ],
    ]);

    $this->assertSame([], $set->descriptors);
    $this->assertArrayHasKey('some_contrib_provider', $set->silent);
    $this->assertSame(1, $set->silent['some_contrib_provider']);
  }

  /**
   * A declared provider alongside an undeclared one still contributes.
   *
   * One silent plugin must not cost the rest of the component its text.
   */
  public function testDeclaredProviderStillResolvesBesideUndeclared(): void {
    $set = $this->resolveWithSettings([
      'props' => [
        'body' => [
          'active' => TRUE,
          'plugins' => [
            'body' => [
              'entity' => ['id' => 'entity', 'settings' => ['field' => 'field_body']],
              'mystery' => ['id' => 'mystery', 'settings' => []],
            ],
          ],
        ],
      ],
    ]);

    $this->assertSame(['field_body'], array_map(
      static fn ($descriptor) => $descriptor->fieldKey,
      $set->descriptors,
    ));
    $this->assertArrayHasKey('mystery', $set->silent);
  }

  /**
   * A component whose settings are malformed resolves to nothing, not an error.
   *
   * Stored component settings drift over a suite's lifetime, and indexing is
   * the last place that should be throwing.
   */
  public function testMalformedSettingsAreSurvived(): void {
    $set = $this->resolveWithSettings([
      'props' => [
        'body' => 'not an array',
        'other' => ['plugins' => 'also not an array'],
        'third' => ['plugins' => ['shape' => NULL]],
      ],
    ]);

    $this->assertSame([], $set->descriptors);
    $this->assertSame([], $set->silent);
  }

  /**
   * Resolves a component whose stored settings are the given array.
   *
   * @param array $settings
   *   The component's `settings` value.
   *
   * @return \Drupal\neo_alchemist_search\Binding\BindingSet
   *   The resolved set.
   */
  private function resolveWithSettings(array $settings) {
    $component = $this->createMock(ComponentInterface::class);
    $component->method('get')->with('settings')->willReturn($settings);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturn($component);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('neo_component')->willReturn($storage);

    // Only `entity` exists; anything else resolves to no definition, which is
    // how an uninstalled or misspelled plugin behaves.
    $valuePluginManager = $this->createMock(ComponentValuePluginManagerInterface::class);
    $valuePluginManager->method('getDefinition')->willReturnCallback(
      static fn (string $id): ?array => $id === 'entity' ? ['class' => EntityValue::class] : NULL,
    );

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    return (new BindingResolver($entityTypeManager, $valuePluginManager, $cache))->resolve('fixture');
  }

}
