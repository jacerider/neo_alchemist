<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Golden list: every ComponentValue plugin's group matches its role.
 *
 * The group is a behavioral contract other code queries, not a form tab:
 * ChildrenShapeBase::childHasOwnValueProvider() is a plain "does the shape
 * own an active providers-group instance", and mislabelling a non-sourcing
 * plugin as `providers` makes children refuse their parent's stored value
 * and silently render the schema examples instead. Group weight is also the
 * pipeline's primary sort key — it is what guarantees `default` runs after
 * every provider.
 *
 * A new plugin therefore MUST update this list consciously; the failure diff
 * names the plugin and the group it landed in.
 */
#[Group('neo_alchemist')]
class ValueGroupTaxonomyTest extends KernelTestBase {

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
   * The full id => group map, sorted by id.
   */
  public function testGoldenGroupMap(): void {
    $definitions = $this->container->get('plugin.manager.neo_component_value')->getDefinitions();
    $map = array_map(static fn (array $definition): string => $definition['group'] ?? '', $definitions);
    ksort($map);

    $this->assertSame([
      'breadcrumb' => 'providers',
      'default' => 'fallback',
      'entity' => 'providers',
      'entity_filter' => 'providers',
      'entity_has_value' => 'providers',
      'entity_load' => 'providers',
      'entity_query' => 'providers',
      'entity_reference' => 'providers',
      'event' => 'providers',
      'formatted_text' => 'modifiers',
      'heading' => 'providers',
      'link_title' => 'modifiers',
      'link_uri' => 'modifiers',
      'media' => 'providers',
      'media_image_size' => 'modifiers',
      'menu' => 'providers',
      // Fixture plugins from neo_alchemist_test.
      'na_cache_tag_value' => 'modifiers',
      'na_record_fallback' => 'fallback',
      'na_record_modifier' => 'modifiers',
      'na_record_provider' => 'providers',
      'na_test_provider' => 'providers',
      'number' => 'modifiers',
      'page_title' => 'providers',
      'prefix' => 'modifiers',
      'region_custom' => 'settings',
      'region_size' => 'settings',
      'suffix' => 'modifiers',
      'token' => 'modifiers',
      'user_has_role' => 'providers',
      // NOTE: the views-backed plugin is absent here because this suite runs
      // with the minimal module set; discovery in this environment is the
      // point — plugins from the module itself plus its fixtures.
      'widget' => 'settings',
    ], $map, 'The plugin group taxonomy drifted — groups are behavioral contracts, so update this list only after checking the consequences (childHasOwnValueProvider(), pipeline ordering).');
  }

  /**
   * Every group is one of the four known roles.
   */
  public function testOnlyKnownGroupsExist(): void {
    $definitions = $this->container->get('plugin.manager.neo_component_value')->getDefinitions();
    $groups = array_unique(array_map(static fn (array $definition): string => $definition['group'] ?? '', $definitions));
    sort($groups);

    $this->assertSame(['fallback', 'modifiers', 'providers', 'settings'], $groups);
  }

}
