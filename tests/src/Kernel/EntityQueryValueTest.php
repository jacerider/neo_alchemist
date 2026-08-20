<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the `entity_query` provider's cacheability and mode default.
 *
 * The provider turns an entity query into a list prop. The thing that goes
 * silently wrong is cacheability: without the queried entity type's LIST cache
 * tags, a rendered listing never refreshes when content is added or removed —
 * a stale page with no error anywhere. The tags must therefore be attached
 * whether or not the query matched anything, because "no results yet" is
 * exactly the state that has to invalidate when the first match appears.
 *
 * Runs against real entity_test storage and a real query rather than a mocked
 * one, so the tags come from the actual entity type definition.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\EntityQueryValue
 */
#[Group('neo_alchemist')]
class EntityQueryValueTest extends KernelTestBase {

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
   * Builds the fixture component with the query provider on `items`.
   */
  private function buildComponent(): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_array_required')) {
      Component::create([
        'id' => 'na_array_required',
        'label' => 'Array required fixture',
        'description' => 'Array required fixture',
        'component' => 'neo_alchemist_test:na_array_required',
        'status' => TRUE,
        // Without a target entity type the component falls back to creating a
        // placeholder node, which this module set has no entity type for.
        'target_entity_type' => 'entity_test',
      ])->save();
    }
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_array_required')
      ->set('settings.props.items.plugins.items', [
        'entity_query' => [
          'id' => 'entity_query',
          'settings' => ['entity_type' => 'entity_test', 'length' => 10],
        ],
      ])
      ->save();
    $storage->resetCache(['na_array_required']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_array_required');
    return $component;
  }

  /**
   * The queried entity type's list cache tags reach the component.
   */
  public function testListCacheTagsAttachedWithResults(): void {
    EntityTest::create(['name' => 'One'])->save();
    EntityTest::create(['name' => 'Two'])->save();

    $component = $this->buildComponent();
    $component->getPropValues();

    $this->assertContains(
      'entity_test_list',
      $component->getCacheableMetadata()->getCacheTags(),
      'The list cache tag reached the component, so adding or removing content invalidates the rendered listing.',
    );
  }

  /**
   * An empty result set still attaches the list cache tags.
   *
   * The subtle half: a listing that currently matches nothing must still
   * invalidate when the first match is created. Attaching the tags only when
   * results were found would cache "nothing here" forever.
   */
  public function testListCacheTagsAttachedWithNoResults(): void {
    $component = $this->buildComponent();
    $values = $component->getPropValues();

    $this->assertContains(
      'entity_test_list',
      $component->getCacheableMetadata()->getCacheTags(),
      'An empty listing is still tagged, so the first matching entity invalidates it.',
    );
    $this->assertArrayNotHasKey('items', $values, 'With nothing matched the prop resolves empty.');
  }

  /**
   * The provider defaults to blocking, not to letting a fallback fill in.
   *
   * A listing that finds nothing should render nothing — falling through to
   * the component's schema examples would put placeholder rows on the page.
   */
  public function testDefaultsToBlockMode(): void {
    $component = $this->buildComponent();
    $shape = $component->getPropShapes()['items'];
    $plugin = $shape->getValueCollection()->get('entity_query');

    $this->assertInstanceOf(ComponentValueProcessingModeInterface::class, $plugin);
    $this->assertSame(
      ComponentValueProcessingModeInterface::MODE_BLOCK,
      $plugin->getProcessingMode(),
      'The query provider blocks by default so an empty listing renders nothing.',
    );
  }

  /**
   * The provider is never author-editable.
   */
  public function testNotEditable(): void {
    $component = $this->buildComponent();
    $plugin = $component->getPropShapes()['items']->getValueCollection()->get('entity_query');

    $this->assertFalse($plugin->isEditable(), 'A query-driven list is not an authorable value.');
  }

  /**
   * The query is exposed as a prop-shape context for slots to reuse.
   *
   * Views-style slots (pagers, headers, exposed filters) read the query back
   * out of the shape context rather than rebuilding it, so losing the context
   * silently breaks paging on every query-driven listing.
   */
  public function testQueryExposedAsPropShapeContext(): void {
    EntityTest::create(['name' => 'One'])->save();

    $component = $this->buildComponent();
    $component->getPropValues();

    $this->assertNotEmpty(
      $component->getPropShapeContexts('entity_query'),
      'The executed query is available to slots through the shape context.',
    );
  }

}
