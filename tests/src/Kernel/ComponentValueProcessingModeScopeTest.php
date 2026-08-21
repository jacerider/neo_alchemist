<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * What the "Processing" mode does NOT govern, and why that is deliberate.
 *
 * The sibling integration suite pins what a mode does to the provider search.
 * This one pins the boundary of that search, because the boundary is the part
 * that reads like an oversight and is not: ComponentShapePluginBase iterates
 * its value plugins in nine places, and exactly one of them — the
 * provideDefaultValue() loop in getDefaultValue() — is a SEARCH, where "which
 * plugin wins" is a question the site builder should get to answer. The other
 * passes are broadcasts and chains, where every plugin is meant to get a turn.
 *
 * Each test here fails if someone widens the mode's reach, which is the point:
 * the cost of widening is invisible by inspection and severe in production.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait
 * @see \Drupal\Tests\neo_alchemist\Kernel\ComponentValueProcessingModeIntegrationTest
 */
#[Group('neo_alchemist')]
class ComponentValueProcessingModeScopeTest extends KernelTestBase {

  use SdcPreviewStoreTestTrait;

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
   * Builds the required probe with the given plugins on its `label` prop.
   *
   * @param array $plugins
   *   Plugin config keyed by plugin id, as stored under
   *   `settings.props.<prop>.plugins.<shape>`.
   */
  private function buildComponent(array $plugins): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_required_probe')) {
      Component::create([
        'id' => 'na_required_probe',
        'label' => 'Required probe fixture',
        'description' => 'Required probe fixture',
        'component' => 'neo_alchemist_test:na_required_probe',
        'status' => TRUE,
      ])->save();
    }
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_required_probe')
      ->set('settings.props.label.plugins.label', $plugins)
      ->save();
    $storage->resetCache(['na_required_probe']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_required_probe');
    return $component;
  }

  /**
   * A provider that claims the value does not switch the modifiers off.
   *
   * The load-bearing test of this suite. Every pass walks one shared instance
   * list sorted providers → fallback → modifiers → settings, and a provider is
   * present in the modifier loop too (its modifyValue() is the base class's
   * identity). So consulting the mode there would let a provider in
   * stop_when_found — the DEFAULT mode — break the loop at its own position,
   * before prefix, suffix, token or formatted_text ever ran. Widening the
   * mode's scope to "every pass a provider takes part in" would therefore
   * disable modifiers on every prop where a provider found a value, sitewide.
   *
   * If someone consults claimsValue() in the modifier loop, this goes red.
   */
  public function testModifiersStillRunAfterProviderClaims(): void {
    $component = $this->buildComponent([
      'na_mode_first' => [
        'id' => 'na_mode_first',
        'settings' => [
          'produce' => 'FIRST',
          'processing_mode' => ComponentValueProcessingModeInterface::MODE_STOP_WHEN_FOUND,
        ],
      ],
      'prefix' => ['id' => 'prefix', 'settings' => ['value' => 'PRE-']],
    ]);

    $values = $component->getPropValues();

    $this->assertSame('PRE-FIRST', $values['label'] ?? NULL, 'The modifier ran even though the provider claimed the value.');
  }

  /**
   * The strongest mode still cannot suppress an authored value.
   *
   * "Always stop (block if empty)" reads like it outranks everything, and it
   * does outrank the rest of the provider search. It must not outrank the
   * content author: the override pass carries what a person typed, and a
   * provider's mode has no business vetoing that. This pins the answer to the
   * question the trait's docblock used to leave open.
   */
  public function testBlockModeDoesNotSuppressAuthoredValue(): void {
    $component = $this->buildComponent([
      'na_mode_first' => [
        'id' => 'na_mode_first',
        'settings' => [
          'produce_empty' => TRUE,
          'processing_mode' => ComponentValueProcessingModeInterface::MODE_BLOCK,
        ],
      ],
    ]);
    $component->setPreview(TRUE);
    $this->setPreviewValues($component, [
      'props' => [
        'label' => [
          'ref' => 'string',
          'value' => ['value' => 'AUTHORED'],
          'options' => ['label' => ['default' => 0]],
        ],
      ],
    ]);

    $values = $component->getPropValues();

    $this->assertSame('AUTHORED', $values['label'] ?? NULL, 'The authored value outranks a blocking provider.');
  }

  /**
   * No shipped plugin implements the override pass.
   *
   * The trait's docblock states that the mode governs the provider search
   * because the other value-shaped pass, provideOverrideValue(), carries the
   * author's value rather than a provider's. That claim rests on nothing in
   * the module implementing it, which is a fact about the code and not a
   * guarantee — so it is pinned rather than asserted in prose.
   *
   * A new implementation is not forbidden; it just has to be a conscious
   * decision, and whoever makes it owns the question of whether that pass
   * should honour the configured mode. The fixtures in neo_alchemist_test
   * implement it deliberately (they exercise the pass) and are out of scope,
   * hence the namespace restriction.
   */
  public function testNoShippedPluginImplementsProvideOverrideValue(): void {
    $definitions = $this->container->get('plugin.manager.neo_component_value')->getDefinitions();

    $implementors = [];
    foreach ($definitions as $pluginId => $definition) {
      $class = $definition['class'] ?? NULL;
      if (!$class || !str_starts_with($class, 'Drupal\\neo_alchemist\\')) {
        continue;
      }
      $declaring = (new \ReflectionClass($class))->getMethod('provideOverrideValue')->getDeclaringClass()->getName();
      if ($declaring !== ComponentValuePluginBase::class) {
        $implementors[] = $pluginId;
      }
    }
    sort($implementors);

    $this->assertSame([], $implementors, 'A shipped plugin now implements provideOverrideValue(); decide whether the override pass should honour the processing mode and update ComponentValueProcessingModeTrait.');
  }

}
