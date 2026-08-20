<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins what is left of the shape's ordering contract after the type took it.
 *
 * ComponentShapePluginBase::init() is one-shot and runs eleven ordered steps.
 * Those constraints used to be assert()s alone, and this test existed to treat
 * them as the specification: each case violated an invariant and required it
 * to be caught. Assertions compile out in production, which is exactly why
 * they needed a test — in dev they were the only thing between a mis-ordered
 * call and silently wrong output.
 *
 * **Most of that moved into the type.** The setters that must run before
 * init() are on ComponentShapeSetupInterface, which the union does not extend,
 * so calling one on an initialised shape no longer compiles. There is nothing
 * left to assert at runtime for those, and the cases that did are gone: what
 * replaced them is ShapeSetupInterfaceTest, which needs no container.
 *
 * What remains here is what a type cannot carry:
 *
 * - The three field-item setters. They are called from onShapeInit(), during
 *   init(), through a shape handle the value plugin holds as the union — a
 *   type cannot withdraw a method from a handle it does not own. Their
 *   deadline is also narrower than init(): the field item, not initialisation.
 * - A getter that must NOT be called too early, which is the opposite
 *   direction and has no type to express it.
 * - That the documented order actually works, so the guards are not over-tight.
 *
 * zend.assertions=1 and assert.exception=On here, so a violation throws
 * AssertionError.
 *
 * @see \Drupal\Tests\neo_alchemist\Unit\ShapeSetupInterfaceTest
 * @see \Drupal\Tests\neo_alchemist\Unit\ChildShapeStateTest
 * @see \Drupal\Tests\neo_alchemist\Unit\NestedOptionMapTest
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

    // getInstance() deliberately returns an *uninitialised* shape — typed
    // ComponentShapeSetupInterface to say so — unlike getPropShapes(), which
    // initialises everything it hands back.
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
   * not trip any of the same guards, or they would be over-tight. It is also
   * the runtime half of the handoff — the setter chains, and init() hands back
   * a shape the type will no longer let anyone set up.
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

    // Everything that must happen before init(), then init() itself. The
    // chain is the point: a setter hands back the shape still under
    // construction, and init() hands back the initialised one.
    $initialised = $shape
      ->setOverrideValue([['title' => ['value' => 'AUTHORED']]])
      ->init();

    $this->assertTrue($initialised->isInitialized());
    $this->assertNotEmpty($initialised->getChildShapes(0), 'Children are reachable once initialised.');
  }

}
