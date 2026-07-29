<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the initialisation-ordering contract of a component shape.
 *
 * ComponentShapePluginBase::init() is one-shot and runs eleven ordered steps,
 * and the class already encodes the resulting constraints as assert()s. Those
 * assertions are the closest thing the module has to a written specification
 * for shape lifecycle, so this test treats them as one: each case violates an
 * invariant and requires it to be caught.
 *
 * They compile out in production, which is exactly why they need a test — in
 * dev they are the only thing standing between a mis-ordered call and silently
 * wrong output. zend.assertions=1 and assert.exception=On here, so a violation
 * throws AssertionError.
 */
#[Group('neo_alchemist')]
class ShapeInitOrderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    Component::create([
      'id' => 'na_array_provider',
      'label' => 'Array provider fixture',
      'description' => 'Array provider fixture',
      'component' => 'neo_alchemist_test:na_array_provider',
      'status' => TRUE,
    ])->save();
  }

  /**
   * Returns the `items` prop shape, already initialised.
   */
  private function initialisedShape(): ComponentShapePluginInterface {
    $component = $this->container->get('entity_type.manager')
      ->getStorage('neo_component')
      ->load('na_array_provider');
    $shape = $component->getPropShape('items');
    $this->assertNotNull($shape, 'The fixture exposes an `items` prop.');
    $this->assertTrue($shape->isInitialized(), 'getPropShapes() initialises what it returns.');
    return $shape;
  }

  /**
   * Assertions are live, or every case below would pass vacuously.
   */
  public function testAssertionsAreEnabled(): void {
    $this->assertSame('1', ini_get('zend.assertions'));
    $this->assertSame('1', ini_get('assert.exception'));
  }

  /**
   * An override value cannot be introduced after initialisation.
   *
   * Init() reads the override to seed the field item; setting one afterwards
   * would be silently ignored rather than applied.
   */
  public function testOverrideValueCannotBeSetAfterInit(): void {
    $shape = $this->initialisedShape();

    $this->expectException(\AssertionError::class);
    $shape->setOverrideValue(['anything']);
  }

  /**
   * Value plugins cannot be re-gated after initialisation.
   *
   * AllowInitPlugins() decides which plugins init() will run, so changing it
   * afterwards cannot retroactively affect anything.
   */
  public function testAllowInitPluginsCannotBeChangedAfterInit(): void {
    $shape = $this->initialisedShape();

    $this->expectException(\AssertionError::class);
    $shape->allowInitPlugins('na_test_provider', FALSE);
  }

  /**
   * The field type is frozen once the field item exists.
   *
   * The field item is built from the type; changing it afterwards would leave
   * the shape describing one type while holding an item of another.
   */
  public function testFieldTypeIsFrozenOnceTheFieldItemExists(): void {
    $shape = $this->initialisedShape();

    $this->expectException(\AssertionError::class);
    $shape->setFieldType('string');
  }

  /**
   * Storage settings are frozen once the field item exists.
   */
  public function testFieldStorageSettingsAreFrozenOnceTheFieldItemExists(): void {
    $shape = $this->initialisedShape();

    $this->expectException(\AssertionError::class);
    $shape->setFieldStorageSettings(['target_type' => 'node']);
  }

  /**
   * Instance settings are frozen once the field item exists.
   */
  public function testFieldInstanceSettingsAreFrozenOnceTheFieldItemExists(): void {
    $shape = $this->initialisedShape();

    $this->expectException(\AssertionError::class);
    $shape->setFieldInstanceSettings(['handler' => 'default']);
  }

  /**
   * Child shapes cannot be requested before the parent is initialised.
   *
   * GetChildShapes() reads the parent's resolved schema and override value,
   * neither of which exists yet — so an early call would build children from
   * incomplete state.
   */
  public function testChildShapesCannotBeRequestedBeforeInit(): void {
    $component = $this->container->get('entity_type.manager')
      ->getStorage('neo_component')
      ->load('na_array_provider');

    // getInstance() deliberately returns an *uninitialised* shape, unlike
    // getPropShapes() which initialises everything it hands back.
    $shape = $this->container->get('plugin.manager.neo_component_shape')->getInstance([
      'schema' => [
        'name' => 'items',
        'type' => ['array'],
        'items' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
      ],
      'settings' => [],
      'component' => $component,
    ]);
    $this->assertNotNull($shape);
    $this->assertFalse($shape->isInitialized());

    $this->expectException(\AssertionError::class);
    $shape->getChildShapes();
  }

  /**
   * The documented lifecycle order actually succeeds.
   *
   * The counterpart to the rejection cases: doing it in the right order must
   * not trip any of the same guards, or they would be over-tight.
   */
  public function testCorrectOrderInitialisesCleanly(): void {
    $component = $this->container->get('entity_type.manager')
      ->getStorage('neo_component')
      ->load('na_array_provider');

    $shape = $this->container->get('plugin.manager.neo_component_shape')->getInstance([
      'schema' => [
        'name' => 'items',
        'type' => ['array'],
        'items' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
      ],
      'settings' => [],
      'component' => $component,
    ]);

    // Everything that must happen before init(), in order.
    $shape->setOverrideValue([['title' => ['value' => 'AUTHORED']]]);
    $shape->init();

    $this->assertTrue($shape->isInitialized());
    $this->assertNotEmpty($shape->getChildShapes(0), 'Children are reachable once initialised.');
  }

}
