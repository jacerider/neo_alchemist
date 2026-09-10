<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the entity query pager slot renders its own pager, or nothing.
 *
 * The slot used to emit a bare ['#type' => 'pager'], which renders pager
 * element 0 — whoever created it. Two things went wrong with that, both
 * silently:
 *
 * - A slot left behind when its prop switched to a provider that does not page
 *   kept rendering. With no pager of its own it showed whatever else on the
 *   page had reached element 0 first, so a listing displayed a foreign pager
 *   whose links did nothing to it. When nothing else had, it emitted an empty
 *   but truthy wrapper, which counts as a filled slot and suppresses the
 *   component's own fallback block.
 * - Element 0 is only what QueryBase::pager() assigns when nothing else on the
 *   page paginated first, so the pairing was accidental rather than guaranteed.
 *
 * The fix is a prop-shape context published only by a provider that actually
 * paginated, carrying the element it claimed.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentSlot\EntityQueryPagerSlot
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\EntityQueryValue
 */
#[Group('neo_alchemist')]
class EntityQueryPagerSlotTest extends KernelTestBase {

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
    // Enough rows to span more than one page, so core does not suppress the
    // pager for having only a single page of results.
    for ($i = 0; $i < 5; $i++) {
      EntityTest::create(['name' => 'Item ' . $i])->save();
    }
  }

  /**
   * Builds the fixture component with the query provider on `items`.
   *
   * @param bool $paging
   *   Whether the provider paginates.
   *
   * @return \Drupal\neo_alchemist\Entity\Component
   *   The component, with its props already resolved.
   */
  private function buildComponent(bool $paging): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_array_required')) {
      Component::create([
        'id' => 'na_array_required',
        'label' => 'Array required fixture',
        'description' => 'Array required fixture',
        'component' => 'neo_alchemist_test:na_array_required',
        'status' => TRUE,
        'target_entity_type' => 'entity_test',
      ])->save();
    }
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_array_required')
      ->set('settings.props.items.plugins.items', [
        'entity_query' => [
          'id' => 'entity_query',
          'settings' => [
            'entity_type' => 'entity_test',
            'length' => 2,
            'paging' => $paging,
          ],
        ],
      ])
      ->save();
    $storage->resetCache(['na_array_required']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_array_required');
    // Slots render after props in Component::toRenderable(); resolving here
    // reproduces that ordering, which is what makes the context available.
    $component->getPropValues();
    return $component;
  }

  /**
   * Builds the pager slot plugin the way ComponentSlot::getPlugins() does.
   */
  private function pagerSlot(Component $component, array $settings = []) {
    return $this->container->get('plugin.manager.neo_component_slot')
      ->createInstance('entity_query_pager', [
        'component' => $component,
        'uuid' => 'e8a1c3d5-0000-4000-8000-000000000001',
        'settings' => $settings,
      ]);
  }

  /**
   * A paging query publishes its pager element as a prop-shape context.
   */
  public function testPagerElementRegisteredAsContext(): void {
    $component = $this->buildComponent(TRUE);

    $contexts = $component->getPropShapeContexts('entity_query_pager');
    $this->assertNotEmpty($contexts, 'A paging query tells slots which pager is its own.');
    $this->assertSame(0, (int) reset($contexts)['value']);
  }

  /**
   * A query with paging off publishes no pager context.
   */
  public function testNoPagerContextWithoutPaging(): void {
    $component = $this->buildComponent(FALSE);

    $this->assertSame(
      [],
      $component->getPropShapeContexts('entity_query_pager'),
      'Without paging there is no pager for a slot to claim.',
    );
  }

  /**
   * The slot renders the element the query claimed, not a hardcoded 0.
   *
   * This is the regression test for the actual defect: reserving element 0
   * first pushes the query onto element 1, exactly as any other pager already
   * on the page would.
   */
  public function testSlotTargetsQueryElementNotZero(): void {
    $this->container->get('pager.manager')->reservePagerElementId(0);

    $component = $this->buildComponent(TRUE);
    $contexts = $component->getPropShapeContexts('entity_query_pager');
    $this->assertSame(1, (int) reset($contexts)['value'], 'The query took the next free element.');

    $build = $this->pagerSlot($component)->toRenderable();
    $this->assertSame('pager', $build['#type']);
    $this->assertSame(1, $build['#element'], 'The slot renders its own query\'s pager.');
  }

  /**
   * A slot with no paging provider behind it renders nothing at all.
   *
   * Reproduces the shape of the config found in the wild: the prop's provider
   * no longer paginates, but the slot survived the swap. An empty array — not
   * an empty-but-truthy render array — is what lets the component's own
   * fallback block show through.
   */
  public function testOrphanedSlotRendersNothing(): void {
    // Something else on the page owns element 0, which is precisely when the
    // old code rendered a foreign pager.
    $this->container->get('pager.manager')->createPager(50, 10, 0);

    $component = $this->buildComponent(FALSE);

    $this->assertSame(
      [],
      $this->pagerSlot($component)->toRenderable(),
      'A stranded pager slot renders nothing rather than borrowing another pager.',
    );
  }

  /**
   * Slot config saved before the context select existed still renders.
   */
  public function testLegacyConfigWithoutContextStillRenders(): void {
    $component = $this->buildComponent(TRUE);

    $build = $this->pagerSlot($component, [])->toRenderable();
    $this->assertSame('pager', $build['#type'], 'An empty context means the component\'s single query.');
    $this->assertSame(0, $build['#element']);
  }

  /**
   * A configured context that no longer resolves renders nothing.
   */
  public function testStaleContextRendersNothing(): void {
    $component = $this->buildComponent(TRUE);

    $this->assertSame(
      [],
      $this->pagerSlot($component, ['context' => 'gone'])->toRenderable(),
      'Explicit config that has gone stale must not fall back to another prop.',
    );
  }

  /**
   * The rows themselves declare the pager cache context.
   *
   * Without this a render-cached listing serves page 1's rows on page 2. It
   * has to come from the provider, not the pager element, because the rows
   * vary by page whether or not a pager is rendered anywhere.
   */
  public function testPagerCacheContextOnComponent(): void {
    $component = $this->buildComponent(TRUE);

    $this->assertContains(
      'url.query_args.pagers:0',
      $component->getCacheableMetadata()->getCacheContexts(),
      'The paged rows vary by page even with no pager slot placed.',
    );
  }

}
