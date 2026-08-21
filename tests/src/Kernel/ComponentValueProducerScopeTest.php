<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Value\ComponentValueProducerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * The producer role is a type, and the type agrees with the legacy group.
 *
 * "Does this plugin source its own value?" used to be answered by comparing the
 * plugin's `group` against the literal string `'providers'`. A plugin's group
 * is also its sort weight and its form tab, so that comparison meant choosing a
 * group for the form silently changed whether nested props kept authored
 * content. The role is now the ComponentValueProducerInterface type, and the
 * consumers ask it (@see \Drupal\neo_alchemist\Value\ComponentValuePluginInterface::isValueProducer()).
 *
 * These reflective assertions are what let that switch be behavior-neutral: for
 * every producer the base install discovers, the type and the group say the
 * same thing, so moving the consumers off the group name loses nothing — and a
 * future plugin cannot source a value without declaring the type, nor declare
 * the type without sourcing a value, without turning one of these red. A rule
 * that only lived in a docblock is exactly the arrangement this replaces.
 *
 * Scope matches the sibling ComponentValueProcessingModeScopeTest: the base
 * module set, filtered to the classes neo_alchemist itself ships. Submodule
 * producers (neo_alchemist_taxonomy) declare the interface too, but are not
 * enabled here, so they are out of this test's discovery set by design.
 *
 * The compatibility shim on ComponentValuePluginBase::isValueProducer() that
 * still honours a `providers` group exists only for producers defined in other
 * packages; these tests prove it never decides the role for a shipped plugin.
 *
 * @see \Drupal\neo_alchemist\Value\ComponentValueProducerInterface
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ChildrenShapeBase::childHasOwnValueProvider()
 */
#[Group('neo_alchemist')]
class ComponentValueProducerScopeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'neo_settings',
    'neo_alchemist',
  ];

  /**
   * Every plugin filed under the `providers` group declares the type.
   *
   * The forward half of the migration: a producer that forgot to declare the
   * interface would still work today through the group shim, so nothing but a
   * reflective check would catch the omission — and a missed producer is the
   * silent, destructive failure the role-as-a-type change exists to prevent.
   */
  public function testEveryProviderGroupPluginDeclaresTheProducerInterface(): void {
    $missing = [];
    foreach ($this->shippedComponentValueDefinitions() as $pluginId => $definition) {
      if ($definition['group'] === 'providers' && !$this->implementsProducerInterface($definition['class'])) {
        $missing[] = $pluginId;
      }
    }
    sort($missing);

    $this->assertSame([], $missing, 'These plugins are in the `providers` group but do not implement ComponentValueProducerInterface, so their producer role rides on the group-name compatibility shim instead of the type.');
  }

  /**
   * Only plugins that source a value declare the type.
   *
   * The reverse half: a modifier, setting or the terminal fallback that
   * declared the interface would be treated as a value source it is not, and
   * would start refusing values pushed down to it from a parent.
   */
  public function testNoNonProviderPluginDeclaresTheProducerInterface(): void {
    $unexpected = [];
    foreach ($this->shippedComponentValueDefinitions() as $pluginId => $definition) {
      if ($definition['group'] !== 'providers' && $this->implementsProducerInterface($definition['class'])) {
        $unexpected[] = $pluginId . ' (' . $definition['group'] . ')';
      }
    }
    sort($unexpected);

    $this->assertSame([], $unexpected, 'These plugins declare ComponentValueProducerInterface but are not in the `providers` group; the type and the group disagree about whether they source a value.');
  }

  /**
   * The ComponentValue definitions shipped by this module (not test doubles).
   *
   * @return array
   *   Plugin definitions keyed by id, restricted to classes this module ships.
   */
  private function shippedComponentValueDefinitions(): array {
    $manager = $this->container->get('plugin.manager.neo_component_value');
    $shipped = [];
    foreach ($manager->getDefinitions() as $pluginId => $definition) {
      $class = $definition['class'] ?? '';
      // The trailing backslash keeps the `_test` fixtures out.
      if (str_starts_with($class, 'Drupal\\neo_alchemist\\')) {
        $shipped[$pluginId] = $definition;
      }
    }
    return $shipped;
  }

  /**
   * Whether a plugin class declares the producer type.
   */
  private function implementsProducerInterface(string $class): bool {
    return (new \ReflectionClass($class))->implementsInterface(ComponentValueProducerInterface::class);
  }

}
