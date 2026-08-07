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
 * Tests entity type and bundle resolution for Search API backed views.
 *
 * A Search API view's base table is `search_api_index_<id>`, which is not any
 * entity type's base or data table, and Search API declares `['table']['entity
 * type']` only on its per-datasource sub-tables — never on the index base
 * table. So neither a base-table comparison nor
 * `ViewExecutable::getBaseEntityType()` finds anything, and the provider used
 * to refuse the view outright. The entity type has to be read off the index's
 * datasources, and the bundle off a filter that names an arbitrary index field
 * ID (`node_type`) rather than the entity's bundle key (`type`).
 *
 * Nothing here executes a query, so no Search API server or backend is needed:
 * resolution reads Views data and index config only.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsValue::getViewEntityTypes()
 * @see \Drupal\search_api\Hook\SearchApiViewsHooks::viewsData()
 */
#[Group('neo_alchemist')]
class ViewsValueSearchApiEntityTypeTest extends KernelTestBase {

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
    'search_api',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // search_api is optional for neo_alchemist, so skip rather than fail where
    // it is not installed alongside.
    if (!class_exists('Drupal\search_api\Entity\Index')) {
      $this->markTestSkipped('The search_api module is not available.');
    }
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['node', 'filter', 'search_api']);
    NodeType::create(['type' => 'insight', 'name' => 'Insight'])->save();
    NodeType::create(['type' => 'project', 'name' => 'Project'])->save();
  }

  /**
   * Creates an index over the given datasources, with no server attached.
   */
  private function createIndex(string $id, array $datasourceSettings): void {
    $this->container->get('entity_type.manager')
      ->getStorage('search_api_index')
      ->create([
        'id' => $id,
        'name' => $id,
        'status' => TRUE,
        'datasource_settings' => $datasourceSettings,
        'field_settings' => [
          'node_type' => [
            'label' => 'Content type',
            'datasource_id' => 'entity:node',
            'property_path' => 'type',
            'type' => 'string',
          ],
        ],
      ])
      ->save();
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
    return $component->getPropShapes()['items']->getValueCollection()->get('views');
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
   * Creates a view over a Search API index and returns its executable.
   */
  private function createView(string $id, string $indexId, array $filters = []): object {
    View::create([
      'id' => $id,
      'label' => $id,
      'base_table' => 'search_api_index_' . $indexId,
      'base_field' => 'search_api_id',
      'display' => [
        'default' => [
          'id' => 'default',
          'display_title' => 'Default',
          'display_plugin' => 'default',
          'position' => 0,
          'display_options' => [
            'query' => ['type' => 'search_api_query'],
            'filters' => $filters,
          ],
        ],
      ],
    ])->save();
    $view = Views::getView($id);
    $view->setDisplay('default');
    return $view;
  }

  /**
   * The entity type comes from the index datasource, the bundle from a filter.
   *
   * This is the case that used to print "the view does not have a
   * corresponding entity type" and hide the whole mapping form.
   */
  public function testIndexDatasourceResolvesTypeAndBundle(): void {
    $this->createIndex('primary', [
      'entity:node' => [
        'bundles' => ['default' => TRUE, 'selected' => []],
        'languages' => ['default' => TRUE, 'selected' => []],
      ],
    ]);
    $plugin = $this->getPlugin();
    // The filter names the index FIELD id, not the entity's bundle key.
    $view = $this->createView('nav_sapi', 'primary', [
      'node_type' => [
        'id' => 'node_type',
        'table' => 'search_api_index_primary',
        'field' => 'node_type',
        'plugin_id' => 'search_api_options',
        'operator' => 'or',
        'value' => ['insight' => 'insight'],
      ],
    ]);

    $entityTypes = $this->call($plugin, 'getViewEntityTypes', [$view]);
    $this->assertSame(['node'], array_keys($entityTypes), 'The entity:node datasource supplies the entity type.');
    $this->assertSame(
      ['insight'],
      $this->call($plugin, 'getViewEntityBundles', [$view, $entityTypes['node']]),
      'node_type resolves through the index to node\'s bundle key.',
    );
  }

  /**
   * A datasource restricted to one bundle pins it without any filter.
   */
  public function testDatasourceBundleRestrictionPinsBundle(): void {
    $this->createIndex('pinned', [
      'entity:node' => [
        'bundles' => ['default' => FALSE, 'selected' => ['insight' => 'insight']],
        'languages' => ['default' => TRUE, 'selected' => []],
      ],
    ]);
    $plugin = $this->getPlugin();
    $view = $this->createView('nav_pinned', 'pinned');

    $entityTypes = $this->call($plugin, 'getViewEntityTypes', [$view]);
    $this->assertSame(
      ['insight'],
      $this->call($plugin, 'getViewEntityBundles', [$view, $entityTypes['node']]),
      'The datasource indexes only Insight, so only Insight fields are offered.',
    );
  }

  /**
   * An unrestricted datasource offers every bundle rather than pinning one.
   *
   * ContentEntity::getBundles() returns a pseudo-bundle named after the entity
   * type when it restricts nothing; treating that as a real restriction would
   * pin the bundle to "node" and offer no fields at all.
   */
  public function testUnrestrictedDatasourceOffersEveryBundle(): void {
    $this->createIndex('open', [
      'entity:node' => [
        'bundles' => ['default' => TRUE, 'selected' => []],
        'languages' => ['default' => TRUE, 'selected' => []],
      ],
    ]);
    $plugin = $this->getPlugin();
    $view = $this->createView('nav_open', 'open');

    $entityTypes = $this->call($plugin, 'getViewEntityTypes', [$view]);
    $this->assertEqualsCanonicalizing(
      ['insight', 'project'],
      $this->call($plugin, 'getViewEntityBundles', [$view, $entityTypes['node']]),
      'Every indexed bundle stays on offer for the form to choose from.',
    );
  }

}
