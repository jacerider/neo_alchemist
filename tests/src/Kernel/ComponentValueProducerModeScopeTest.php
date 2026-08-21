<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\Value\ComponentValueProducerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every producer either claims through a mode or is a named exception.
 *
 * A producer sources a prop's value, and the provider search decides whether
 * that value claims — halting the search so a later producer cannot overwrite
 * it — by asking the producer's ComponentValueProcessingModeInterface. A
 * producer WITHOUT that interface can never claim: it hands its value forward
 * and any later provider silently replaces it. `read_time` shipped exactly that
 * way and it was a defect (fixed by giving it the mode).
 *
 * So "a producer with no mode" is a claim about a plugin that must be a
 * conscious decision, not an accident. Five producers legitimately have no
 * site-builder mode, for two distinct reasons, and this test makes that set
 * KNOWABLE rather than something a maintainer rediscovers while debugging why a
 * value did not stick:
 *
 * - The two vetoes (`entity_has_value`, `user_has_role`) claim by hand inside
 *   provide(), so the search never consults a mode for them — the mode select
 *   would be a lie about what governs them.
 * - The three view-context producers source their value in the MODIFY phase,
 *   not the provide phase, because the view has not executed at shape-init
 *   time. They take no part in the provider search where a mode would act, so a
 *   mode on them would configure a phase they never run in. Two of them declare
 *   that ordering through the `late` group; `views_exposed_filter` is the same
 *   kind of plugin and is inventoried here for the same reason.
 *
 * `views` is enabled so those three are discovered — they declare
 * `provider: 'views'` and are invisible without it, which is precisely how they
 * escaped inventory before. Any NEW producer added without a mode turns this
 * red, and its author must either give it a mode or add it here with a reason.
 *
 * @see \Drupal\neo_alchemist\Value\ComponentValueProducerInterface
 * @see \Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface
 * @see \Drupal\neo_alchemist\ValueProviderSearch
 * @see \Drupal\Tests\neo_alchemist\Kernel\ComponentValueProducerScopeTest
 */
#[Group('neo_alchemist')]
class ComponentValueProducerModeScopeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'views',
    'neo_settings',
    'neo_alchemist',
  ];

  /**
   * Producers that ship without a processing mode, and why each is allowed to.
   *
   * This IS the deliverable: the set is named here so it is knowable, and the
   * reason is recorded next to each id so a future reader knows whether a new
   * omission belongs on the list or is a bug.
   */
  private const MODELESS_PRODUCERS = [
    // Vetoes: claim by hand in provide(), so no mode governs them.
    'entity_has_value',
    'user_has_role',
    // Late-sourcing: resolve in modifyValue(), outside the provider search.
    'views_summary',
    'views_active_filters',
    'views_exposed_filter',
  ];

  /**
   * A producer without a mode must be one of the named exceptions.
   */
  public function testEveryProducerDeclaresModeOrIsKnownException(): void {
    $unaccounted = [];
    foreach ($this->shippedComponentValueDefinitions() as $pluginId => $definition) {
      $class = $definition['class'];
      if (!$this->implementsInterface($class, ComponentValueProducerInterface::class)) {
        continue;
      }
      if ($this->implementsInterface($class, ComponentValueProcessingModeInterface::class)) {
        continue;
      }
      if (in_array($pluginId, self::MODELESS_PRODUCERS, TRUE)) {
        continue;
      }
      $unaccounted[] = $pluginId;
    }
    sort($unaccounted);

    $this->assertSame([], $unaccounted, 'These plugins source a value (ComponentValueProducerInterface) but carry no processing mode, so the provider search can never let them claim it — a later provider will silently overwrite them. Give each one ComponentValueProcessingModeInterface, or add it to ComponentValueProducerModeScopeTest::MODELESS_PRODUCERS with the reason it is allowed to have none.');
  }

  /**
   * The exception list carries no id that has since gained a mode.
   *
   * The list is a liability, not an asset: an entry that is no longer needed
   * (because the plugin gained a mode, like `read_time` did) hides the next
   * real omission. This keeps the list honest in the other direction.
   */
  public function testNoExceptionStillNeedsToBeAnException(): void {
    $definitions = $this->shippedComponentValueDefinitions();
    $stale = [];
    foreach (self::MODELESS_PRODUCERS as $pluginId) {
      $class = $definitions[$pluginId]['class'] ?? NULL;
      // A missing plugin id is a separate concern (a rename), not staleness.
      if ($class === NULL) {
        continue;
      }
      if ($this->implementsInterface($class, ComponentValueProcessingModeInterface::class)) {
        $stale[] = $pluginId;
      }
    }
    sort($stale);

    $this->assertSame([], $stale, 'These ids are on the mode-less exception list but now implement ComponentValueProcessingModeInterface; drop them from ComponentValueProducerModeScopeTest::MODELESS_PRODUCERS.');
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
   * Whether a plugin class declares the given interface.
   */
  private function implementsInterface(string $class, string $interface): bool {
    return (new \ReflectionClass($class))->implementsInterface($interface);
  }

}
