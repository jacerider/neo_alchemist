<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\node\Entity\NodeType;
use Drupal\views\Entity\View;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests how the `views` provider resolves a view's entity type and bundle.
 *
 * The provider maps view rows onto child shapes, and to build that mapping form
 * it needs one entity type and, ideally, one bundle: with a NULL bundle the
 * field matcher builds `entity:node` instead of `entity:node:insight` and only
 * base fields are offered, so every configured `field_*` silently disappears
 * from the UI.
 *
 * Resolution used to be a single string comparison of the view's base table
 * against each entity type's base/data table. That covers core entity views and
 * nothing else — a Search API view's base table is `search_api_index_<id>`,
 * which matches no entity type, so the whole mapping form was replaced by "the
 * view does not have a corresponding entity type". Search API declares the
 * entity type only on its per-datasource sub-tables, never on the index base
 * table, so `ViewExecutable::getBaseEntityType()` does not rescue it either;
 * the type has to come from the index's datasources.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsValue::getViewEntityTypes()
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsValue::getViewEntityBundles()
 */
#[Group('neo_alchemist')]
class ViewsValueEntityTypeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'views',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['node', 'filter']);
    NodeType::create(['type' => 'insight', 'name' => 'Insight'])->save();
    NodeType::create(['type' => 'project', 'name' => 'Project'])->save();
  }

  /**
   * Returns the `views` provider instance bound to a real array shape.
   */
  private function getPlugin(): object {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_array_required')) {
      Component::create([
        'id' => 'na_array_required',
        'label' => 'Array required fixture',
        'description' => 'Array required fixture',
        'component' => 'neo_alchemist_test:na_array_required',
        'status' => TRUE,
        'target_entity_type' => 'node',
      ])->save();
    }
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_array_required')
      ->set('settings.props.items.plugins.items', [
        'views' => ['id' => 'views', 'settings' => []],
      ])
      ->save();
    $storage->resetCache(['na_array_required']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_array_required');
    $plugin = $component->getPropShapes()['items']->getValueCollection()->get('views');
    $this->assertNotNull($plugin, 'The views provider is available with the views module enabled.');
    return $plugin;
  }

  /**
   * Calls one of the provider's protected resolution methods.
   */
  private function call(object $plugin, string $method, array $args): mixed {
    $ref = new \ReflectionMethod($plugin, $method);
    $ref->setAccessible(TRUE);
    return $ref->invokeArgs($plugin, $args);
  }

  /**
   * Creates a view and returns its executable with the display set.
   */
  private function createView(string $id, string $baseTable, array $filters = []): object {
    View::create([
      'id' => $id,
      'label' => $id,
      'base_table' => $baseTable,
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => ['filters' => $filters],
        ],
      ],
    ])->save();
    $view = Views::getView($id);
    $view->setDisplay('default');
    return $view;
  }

  /**
   * A core entity view resolves through its base table, bundle via its filter.
   */
  public function testCoreEntityViewResolvesTypeAndBundle(): void {
    $plugin = $this->getPlugin();
    $view = $this->createView('nav_core', 'node_field_data', [
      'type' => [
        'id' => 'type',
        'table' => 'node_field_data',
        'field' => 'type',
        'plugin_id' => 'bundle',
        'entity_type' => 'node',
        'entity_field' => 'type',
        'operator' => 'in',
        'value' => ['insight' => 'insight'],
      ],
    ]);

    $entityTypes = $this->call($plugin, 'getViewEntityTypes', [$view]);
    $this->assertSame(['node'], array_keys($entityTypes), 'node_field_data is node\'s data table.');
    $this->assertSame(
      ['insight'],
      $this->call($plugin, 'getViewEntityBundles', [$view, $entityTypes['node']]),
      'The single-value bundle filter narrows the offered fields to Insight.',
    );
  }

  /**
   * A bundle filter that says what the bundle is NOT yields no restriction.
   *
   * Reading the value off a negated filter would offer exactly the fields the
   * results cannot have.
   */
  public function testNegatedBundleFilterYieldsNoRestriction(): void {
    $plugin = $this->getPlugin();
    $view = $this->createView('nav_negated', 'node_field_data', [
      'type' => [
        'id' => 'type',
        'table' => 'node_field_data',
        'field' => 'type',
        'plugin_id' => 'bundle',
        'entity_type' => 'node',
        'entity_field' => 'type',
        'operator' => 'not in',
        'value' => ['insight' => 'insight'],
      ],
    ]);
    $entityTypes = $this->call($plugin, 'getViewEntityTypes', [$view]);

    $this->assertSame(
      [],
      $this->call($plugin, 'getViewBundleFilterValues', [$view, $entityTypes['node']]),
      'A negated filter is not treated as a bundle restriction.',
    );
    $bundles = $this->call($plugin, 'getViewEntityBundles', [$view, $entityTypes['node']]);
    $this->assertEqualsCanonicalizing(['insight', 'project'], $bundles, 'All bundles stay on offer.');
  }

  /**
   * A filter matching more than one bundle does not pin a single bundle.
   */
  public function testMultiValueBundleFilterOffersEachBundle(): void {
    $plugin = $this->getPlugin();
    $view = $this->createView('nav_multi', 'node_field_data', [
      'type' => [
        'id' => 'type',
        'table' => 'node_field_data',
        'field' => 'type',
        'plugin_id' => 'bundle',
        'entity_type' => 'node',
        'entity_field' => 'type',
        'operator' => 'in',
        'value' => ['insight' => 'insight', 'project' => 'project'],
      ],
    ]);
    $entityTypes = $this->call($plugin, 'getViewEntityTypes', [$view]);

    $this->assertEqualsCanonicalizing(
      ['insight', 'project'],
      $this->call($plugin, 'getViewEntityBundles', [$view, $entityTypes['node']]),
      'Both bundles are offered so the form can ask which one to map.',
    );
  }

  /**
   * A view over a non-entity base table resolves to nothing.
   *
   * Its rows carry no `_entity`, so the mapping form must refuse rather than
   * present a field list nothing will fill.
   */
  public function testNonEntityViewResolvesToNoEntityType(): void {
    $plugin = $this->getPlugin();
    $view = $this->createView('nav_none', 'watchdog');

    $this->assertSame([], $this->call($plugin, 'getViewEntityTypes', [$view]));
  }

}
