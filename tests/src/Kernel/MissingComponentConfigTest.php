<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that a component pointing at a missing SDC keeps its configuration.
 *
 * ComponentPluginManager::setCachedDefinitions() re-derives and re-saves any
 * neo_component whose expression has drifted. Both the expression and the props
 * skeleton are generated from the SDC's schema, so when the SDC is absent they
 * come out empty — and saving that empty result wipes every value provider,
 * slot and filter a site builder configured.
 *
 * The window is not exotic. A component id is a plain string in config, so it
 * dangles for as long as it takes to finish a rename, deploy a theme, or
 * reinstall a module — and any `drush cr` in that window used to be enough to
 * destroy the configuration silently. This is the regression guard for that.
 */
#[Group('neo_alchemist')]
class MissingComponentConfigTest extends KernelTestBase {

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
   * Rebuilds SDC definitions, which is what triggers the re-save sweep.
   */
  protected function rebuildDefinitions(): void {
    $manager = $this->container->get('plugin.manager.sdc');
    $manager->clearCachedDefinitions();
    $manager->getDefinitions();
  }

  /**
   * Loads the component entity fresh from storage.
   */
  protected function reload(string $id) {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $storage->resetCache([$id]);
    return $storage->load($id);
  }

  /**
   * Creates a component entity and returns the id it actually got.
   *
   * Component::save() derives the id from the SDC machine name on create, so
   * whatever is passed in is discarded.
   */
  protected function createProbe(): string {
    $entity = $this->container->get('entity_type.manager')->getStorage('neo_component')->create([
      'label' => 'Probe',
      'description' => 'Fixture for the missing-SDC guard.',
      'group' => 'general',
      'component' => 'neo_alchemist_test:na_leaf',
      'status' => TRUE,
    ]);
    $entity->save();
    return $entity->id();
  }

  /**
   * Tests that a dangling component id does not cost the site its settings.
   */
  public function testMissingComponentKeepsItsSettings(): void {
    $id = $this->createProbe();

    $settings = $this->reload($id)->get('settings');
    $this->assertNotEmpty($settings['props'] ?? [], 'The fixture resolved a props skeleton to begin with.');

    // Point it at an SDC that does not exist, the way a half-applied rename
    // does. Written through the config factory so the entity's own save path —
    // which would re-derive against the missing component — is not what puts
    // the config into this state.
    $this->config('neo_alchemist.neo_component.' . $id)
      ->set('component', 'front:renamed_away')
      ->save();

    $this->rebuildDefinitions();

    $after = $this->reload($id);
    $this->assertSame('front:renamed_away', $after->get('component'), 'The dangling pointer is left as-is.');
    $this->assertSame($settings, $after->get('settings'), 'Settings survived the rebuild.');
  }

  /**
   * Tests that a resolvable component is still re-derived as before.
   *
   * The guard must not turn the sweep off — a component whose SDC gained or
   * lost a prop should still be brought back in line on the next rebuild.
   */
  public function testResolvableComponentIsStillReSaved(): void {
    $id = $this->createProbe();

    $expression = $this->reload($id)->getExpression();
    $this->assertNotEmpty($expression);

    // Corrupt the stored expression so it no longer matches the SDC.
    $this->config('neo_alchemist.neo_component.' . $id)
      ->set('expression', 'stale')
      ->save();

    $this->rebuildDefinitions();

    $this->assertSame($expression, $this->reload($id)->getExpression(), 'A resolvable component was re-derived.');
  }

}
