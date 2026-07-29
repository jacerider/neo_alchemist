<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use PHPUnit\Framework\Attributes\Group;

/**
 * Orphaned hybrid content survives, stays inert, and never resurrects.
 *
 * Orphans are stored sections whose custom-region anchor no longer exists in
 * the field default layout. The contract: they are carried through saves
 * (so a config revert restores the content), never rendered, kept through
 * in-session merged-tree sets (an editor commit cannot express them), and
 * replaced — not accumulated — when a fresh storage subset arrives.
 *
 * The layout here places TWO na_region_host instances, both anchors via the
 * same component's region_custom flag; removing host B from the defaults
 * orphans its stored content while host A keeps the field hybrid.
 */
#[Group('neo_alchemist')]
class HybridOrphanPreservationTest extends HybridFieldKernelTestBase {

  /**
   * The second region-host instance uuid.
   */
  protected const HOST_B_UUID = 'host-b-instance-uuid';

  /**
   * {@inheritdoc}
   */
  protected function defaultLayout(): array {
    $layout = parent::defaultLayout();
    $layout['tree'][ComponentTreeStructure::ROOT_UUID][] = [
      'uuid' => static::HOST_B_UUID,
      'component' => 'na_region_host',
    ];
    $layout['tree'][static::HOST_B_UUID] = ['body' => []];
    $layout['props'][static::HOST_B_UUID] = [
      'status' => TRUE,
      'props' => [
        'heading' => ['ref' => 'string', 'value' => ['value' => 'HOST B HEADING']],
      ],
    ];
    return $layout;
  }

  /**
   * A layout without host B, e.g. after a site-builder edit.
   */
  private function layoutWithoutHostB(): array {
    $layout = $this->defaultLayout();
    $layout['tree'][ComponentTreeStructure::ROOT_UUID] = array_values(array_filter(
      $layout['tree'][ComponentTreeStructure::ROOT_UUID],
      static fn (array $tuple): bool => $tuple['uuid'] !== static::HOST_B_UUID,
    ));
    unset($layout['tree'][static::HOST_B_UUID], $layout['props'][static::HOST_B_UUID]);
    return $layout;
  }

  /**
   * Swaps the field default layout and drops every derived cache.
   */
  private function setDefaultLayout(array $layout): void {
    $field = FieldConfig::loadByName('entity_test', 'entity_test', static::FIELD_NAME);
    $field->setSetting('defaults', $layout);
    $field->save();
    $this->resetFieldCaches('na_region_host');
  }

  /**
   * Authors content in both hosts' regions and returns the saved entity.
   */
  private function entityWithBothRegionsAuthored(): EntityTest {
    $entity = $this->createTestEntity();
    $this->assertFieldIsHybrid($entity);
    $defaults = $this->defaultLayout();
    $tree = $defaults['tree'];
    $tree[static::HOST_UUID]['body'] = [['uuid' => 'a-leaf', 'component' => 'na_leaf']];
    $tree[static::HOST_B_UUID]['body'] = [['uuid' => 'b-leaf', 'component' => 'na_leaf']];
    $entity->set(static::FIELD_NAME, [
      [
        'tree' => $tree,
        'props' => $defaults['props'] + [
          'a-leaf' => $this->leafProps('AUTHORED A'),
          'b-leaf' => $this->leafProps('AUTHORED B'),
        ],
      ],
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * Orphaned content survives save cycles and stays render-inert.
   */
  public function testOrphanSurvivesSavesAndStaysInert(): void {
    $entity = $this->entityWithBothRegionsAuthored();

    // The site builder removes host B from the default layout.
    $this->setDefaultLayout($this->layoutWithoutHostB());

    // Two full load/save cycles.
    foreach ([1, 2] as $cycle) {
      $entity = $this->reloadEntity($entity);
      $entity->save();
      $stored = $this->rawStoredValue($this->reloadEntity($entity));
      $this->assertSame(
        [['uuid' => 'b-leaf', 'component' => 'na_leaf']],
        $stored['tree'][static::HOST_B_UUID]['body'] ?? NULL,
        sprintf('The orphaned section survived save cycle %d.', $cycle),
      );
      $this->assertSame($this->leafProps('AUTHORED B'), $stored['props']['b-leaf'] ?? NULL, sprintf('The orphaned props survived save cycle %d.', $cycle));
    }

    // Render-inert: the merged runtime value knows nothing of host B.
    $merged = json_decode($this->reloadEntity($entity)->get(static::FIELD_NAME)->first()->getValue()['tree'], TRUE);
    $this->assertArrayNotHasKey(static::HOST_B_UUID, $merged, 'The orphaned section is not merged.');
  }

  /**
   * Restoring the anchor restores the orphaned content.
   */
  public function testOrphanRestoredWhenAnchorReturns(): void {
    $entity = $this->entityWithBothRegionsAuthored();
    $this->setDefaultLayout($this->layoutWithoutHostB());

    // A save cycle while orphaned.
    $entity = $this->reloadEntity($entity);
    $entity->save();

    // The site builder reverts the layout.
    $this->setDefaultLayout($this->defaultLayout());

    $merged = json_decode($this->reloadEntity($entity)->get(static::FIELD_NAME)->first()->getValue()['tree'], TRUE);
    $this->assertSame(
      [['uuid' => 'b-leaf', 'component' => 'na_leaf']],
      $merged[static::HOST_B_UUID]['body'] ?? NULL,
      'The authored content came back with the anchor.',
    );
  }

  /**
   * Orphans survive an in-session merged-tree set (the commit-flow shape).
   *
   * An editor commit pushes the merged runtime value — which cannot express
   * orphans — back through setValue(). The load-time orphan stash must
   * survive that, or every commit on an entity carrying orphans destroys
   * them.
   */
  public function testOrphansSurviveInSessionMergedTreeSet(): void {
    $entity = $this->entityWithBothRegionsAuthored();
    $this->setDefaultLayout($this->layoutWithoutHostB());

    // Load (stashes the host B orphan), then an in-session edit of host A's
    // region expressed as the full merged tree, then save.
    $entity = $this->reloadEntity($entity);
    $layout = $this->layoutWithoutHostB();
    $tree = $layout['tree'];
    $tree[static::HOST_UUID]['body'] = [['uuid' => 'a-leaf-2', 'component' => 'na_leaf']];
    $entity->set(static::FIELD_NAME, [
      [
        'tree' => $tree,
        'props' => $layout['props'] + ['a-leaf-2' => $this->leafProps('AUTHORED A EDIT')],
      ],
    ]);
    $entity->save();

    $stored = $this->rawStoredValue($this->reloadEntity($entity));
    $this->assertSame(
      [['uuid' => 'a-leaf-2', 'component' => 'na_leaf']],
      $stored['tree'][static::HOST_UUID]['body'] ?? NULL,
      'The in-session edit persisted.',
    );
    $this->assertSame(
      [['uuid' => 'b-leaf', 'component' => 'na_leaf']],
      $stored['tree'][static::HOST_B_UUID]['body'] ?? NULL,
      'The orphan survived the in-session merged-tree set.',
    );
    $this->assertSame($this->leafProps('AUTHORED B'), $stored['props']['b-leaf'] ?? NULL);
  }

  /**
   * A fresh storage subset replaces stashed orphans — no resurrection.
   *
   * When an authoritative subset (empty root section) arrives on a list that
   * stashed orphans from an older stored value, the old stash must be
   * dropped: re-emitting it would resurrect content the new stored state no
   * longer contains.
   */
  public function testFreshSubsetReplacesOrphans(): void {
    $entity = $this->entityWithBothRegionsAuthored();
    $this->setDefaultLayout($this->layoutWithoutHostB());

    // Load: the list stashes the host B orphan.
    $entity = $this->reloadEntity($entity);

    // An authoritative subset arrives without host B (e.g. programmatic
    // replacement of the stored state): empty root marks it as a subset.
    $entity->set(static::FIELD_NAME, [
      [
        'tree' => [
          ComponentTreeStructure::ROOT_UUID => [],
          static::HOST_UUID => ['body' => [['uuid' => 'a-leaf-3', 'component' => 'na_leaf']]],
        ],
        'props' => ['a-leaf-3' => $this->leafProps('REPLACED A')],
      ],
    ]);
    $entity->save();

    $stored = $this->rawStoredValue($this->reloadEntity($entity));
    $this->assertArrayNotHasKey(static::HOST_B_UUID, $stored['tree'], 'The stale orphan did not resurrect past the new authoritative subset.');
    $this->assertArrayNotHasKey('b-leaf', $stored['props']);
    $this->assertSame(
      [['uuid' => 'a-leaf-3', 'component' => 'na_leaf']],
      $stored['tree'][static::HOST_UUID]['body'] ?? NULL,
    );
  }

}
