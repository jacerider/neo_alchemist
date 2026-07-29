<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Saving an unchanged component is byte-idempotent.
 *
 * Component::preSave() regenerates settings.props, expression and schema
 * from the live SDC on every save, and setPropShapeSettings() unsets the
 * memoised shapes mid-loop — a drift here silently evaporates per-prop value
 * plugin configuration (region_custom flags, provider settings), which is
 * invisible until a render goes blank or a hybrid field flips mode.
 *
 * @see \Drupal\neo_alchemist\Entity\Component::preSave()
 */
#[Group('neo_alchemist')]
class ComponentSaveIdempotenceTest extends KernelTestBase {

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
   * Creates a component and returns the relevant slice of its raw config.
   */
  private function rawConfig(string $id): array {
    $data = $this->container->get('config.factory')->get("neo_alchemist.neo_component.$id")->getRawData();
    return array_intersect_key($data, array_flip(['settings', 'expression', 'schema']));
  }

  /**
   * Creates a fixture component.
   */
  private function createComponent(string $id): void {
    Component::create([
      'id' => $id,
      'label' => $id,
      'description' => $id,
      'component' => 'neo_alchemist_test:' . $id,
      'status' => TRUE,
    ])->save();
  }

  /**
   * A load/save cycle leaves settings, expression and schema untouched.
   */
  public function testResaveIsByteIdentical(): void {
    $this->createComponent('na_array_required');
    $before = $this->rawConfig('na_array_required');

    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $storage->resetCache(['na_array_required']);
    $storage->load('na_array_required')->save();

    $this->assertSame($before, $this->rawConfig('na_array_required'), 'A plain resave changed the stored component.');
  }

  /**
   * Per-prop plugin configuration survives a resave.
   *
   * This is the production shape of the risk: hero_s2 / project_full carry
   * region_custom flags, and every editor save of the component runs the
   * preSave() update branch. Losing the flag silently flips every hybrid
   * field using the component back to locked mode.
   */
  public function testRegionCustomFlagSurvivesResave(): void {
    $this->createComponent('na_region_host');
    // The flag is written by raw config after the first save (preSave
    // regenerates settings.props on the initial save).
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_region_host')
      ->set('settings.props.body.plugins.body.region_custom', [
        'id' => 'region_custom',
        'settings' => [],
      ])
      ->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $storage->resetCache(['na_region_host']);
    $before = $this->rawConfig('na_region_host');

    $storage->load('na_region_host')->save();
    $after = $this->rawConfig('na_region_host');

    $this->assertArrayHasKey(
      'region_custom',
      $after['settings']['props']['body']['plugins']['body'] ?? [],
      'The region_custom flag survived the resave.',
    );
    $this->assertSame($before, $after, 'The resave round-tripped the stored plugin configuration byte-identically.');
  }

}
