<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Tests\neo_alchemist\Traits\ShapeDoubleTrait;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\ComponentShapeContextInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityHasValue;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the `entity_has_value` veto provider.
 *
 * A content-presence gate: when the configured entity field holds nothing,
 * the provider returns FALSE and claims the value, so no fallback can put a
 * truthy value back and reveal a component that has nothing to show. Same
 * family as the role veto, and the same silent failure mode — invert it and
 * components appear on pages whose content is missing, or vanish from pages
 * that have it.
 *
 * Runs against a real MatcherField and a real entity, because the whole point
 * of the plugin is reading a field off an entity; a mocked matcher would only
 * restate the test's own assumptions. MatcherField is final, so a Unit test
 * could not mock it anyway.
 *
 * Worth knowing while reading the plugin: its local is named `$isEmpty` but
 * holds "the field HAS a value" — the assertions below are written against
 * behavior precisely so that inverting the sense fails loudly here.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\EntityHasValue
 * @see \Drupal\Tests\neo_alchemist\Unit\EntityProviderAccessGateTest
 */
#[Group('neo_alchemist')]
class EntityHasValueTest extends KernelTestBase {

  use ShapeDoubleTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
    'neo_settings',
    'neo_alchemist',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');
  }

  /**
   * Builds the plugin over a real entity, ready to run.
   *
   * @param \Drupal\entity_test\Entity\EntityTest $entity
   *   The entity the shape reports.
   * @param string $field
   *   The configured field key.
   */
  private function plugin(EntityTest $entity, string $field = 'name'): EntityHasValue {
    // What entity to read is the context role's; collecting cacheability is
    // the Drupal interface the union also extends, and neither is the other's.
    $context = $this->shapeRole(ComponentShapeContextInterface::class);
    $context->method('getEntity')->willReturn($entity);
    $cacheable = $this->shapeRole(CacheableResponseInterface::class);
    $cacheable->method('getCacheableMetadata')->willReturn(new CacheableMetadata());
    $shape = $this->shapeDouble([$context, $cacheable]);

    return new EntityHasValue(
      'entity_has_value',
      [],
      $shape,
      ['field' => $field],
      $this->container->get('neo_alchemist.matcher_field'),
    );
  }

  /**
   * A field with content lets the value through and leaves the pipeline open.
   */
  public function testFieldWithValuePassesThrough(): void {
    $entity = EntityTest::create(['name' => 'Has content']);
    $entity->save();

    $provision = $this->plugin($entity)->provide(TRUE);

    $this->assertTrue($provision->getValue(), 'The incoming value survived untouched.');
    $this->assertFalse($provision->isClaimed(), 'Later providers may still run.');
  }

  /**
   * An empty field vetoes: FALSE, and the pipeline halts.
   */
  public function testEmptyFieldVetoes(): void {
    $entity = EntityTest::create(['name' => NULL]);
    $entity->save();

    $provision = $this->plugin($entity)->provide(TRUE);

    $this->assertFalse($provision->getValue(), 'An absent value resolves the gate to FALSE.');
    $this->assertTrue($provision->isClaimed(), 'The claim stops a fallback from revealing the component.');
  }

  /**
   * An empty string counts as no content.
   */
  public function testEmptyStringVetoes(): void {
    $entity = EntityTest::create(['name' => '']);
    $entity->save();

    $provision = $this->plugin($entity)->provide(TRUE);

    $this->assertFalse($provision->getValue());
    $this->assertTrue($provision->isClaimed());
  }

  /**
   * A field key that does not exist vetoes rather than passing through.
   *
   * Fails closed: a renamed or removed field hides the component instead of
   * silently letting it render with nothing behind it.
   */
  public function testUnknownFieldVetoes(): void {
    $entity = EntityTest::create(['name' => 'Has content']);
    $entity->save();

    $provision = $this->plugin($entity, 'field_that_does_not_exist')->provide(TRUE);

    $this->assertFalse($provision->getValue(), 'An unresolvable field key fails closed.');
    $this->assertTrue($provision->isClaimed());
  }

  /**
   * The gate never becomes an authorable value.
   */
  public function testNotEditable(): void {
    $entity = EntityTest::create(['name' => 'Has content']);
    $entity->save();

    $this->assertFalse($this->plugin($entity)->isEditable());
  }

}
