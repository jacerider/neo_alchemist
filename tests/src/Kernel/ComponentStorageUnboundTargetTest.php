<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * An unbound component reaches the library however its config spells "unbound".
 *
 * `target_entity_type` has two falsy spellings in the wild. ComponentForm
 * submits its `- All -` option as '', while a component created any other way
 * — config import, Component::create() without the key, a clone — never
 * initialises the typed property at all, so ConfigEntityBase::get() yields NULL
 * and the export writes `target_entity_type: null`.
 *
 * The config entity query treats those as different values: its match() bails
 * on `isset($value)` before the '=' comparison, so a NULL never equals '' — or
 * anything else. A `= ''` condition alone therefore drops every NULL-targeted
 * component out of loadByEntity(), and because that is the only source the
 * library controller draws from, the component simply is not offered anywhere,
 * with no error to explain the absence.
 *
 * @see \Drupal\neo_alchemist\ComponentStorage::loadByEntity()
 * @see \Drupal\Core\Config\Entity\Query\Condition::match()
 * @see \Drupal\neo_alchemist\Controller\InstanceComponentLibraryController
 */
#[Group('neo_alchemist')]
class ComponentStorageUnboundTargetTest extends KernelTestBase {

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
    'neo_alchemist_test',
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
   * Creates a component, optionally spelling its unbound target explicitly.
   *
   * Component::save() re-derives a new component's id from its SDC id, so the
   * id is read back rather than assumed.
   */
  private function createComponent(string $sdc, array $extra = []): string {
    $component = Component::create([
      'label' => $sdc,
      'description' => $sdc,
      'component' => 'neo_alchemist_test:' . $sdc,
      'status' => TRUE,
    ] + $extra);
    $component->save();
    return $component->id();
  }

  /**
   * Both spellings of "no target entity type" are offered for any host.
   */
  public function testNullAndEmptyTargetsBothLoad(): void {
    // The form path: the empty option arrives as ''.
    $emptyId = $this->createComponent('na_leaf', ['target_entity_type' => '']);
    // The config-import path: the key is never set, so it exports as NULL.
    // Component::save() derives a distinct id from the same SDC.
    $nullId = $this->createComponent('na_leaf');
    $this->assertNotSame($emptyId, $nullId, 'The two fixtures are distinct components.');

    $this->assertNull(
      $this->config("neo_alchemist.neo_component.$nullId")->get('target_entity_type'),
      'The fixture reproduces the NULL spelling this test exists for.',
    );

    $host = EntityTest::create(['name' => 'Host']);
    $host->save();

    $loaded = $this->container->get('entity_type.manager')
      ->getStorage('neo_component')
      ->loadByEntity($host);

    $this->assertArrayHasKey($emptyId, $loaded, "The ''-targeted component is offered for the host entity.");
    $this->assertArrayHasKey($nullId, $loaded, 'The NULL-targeted component is offered for the host entity.');
  }

  /**
   * Widening to NULL did not widen the query past unbound components.
   *
   * `notExists()` must only add the components that declare no target at all —
   * a component bound to another entity type still has to stay out of this
   * host's library.
   */
  public function testForeignTargetStillExcluded(): void {
    $foreignId = $this->createComponent('na_leaf', ['target_entity_type' => 'user']);

    $host = EntityTest::create(['name' => 'Host']);
    $host->save();

    $loaded = $this->container->get('entity_type.manager')
      ->getStorage('neo_component')
      ->loadByEntity($host);

    $this->assertArrayNotHasKey($foreignId, $loaded, 'A component bound to another entity type stayed out of the library.');
  }

}
