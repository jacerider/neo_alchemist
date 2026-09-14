<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the `entity_query` provider's cacheability, mode default and filters.
 *
 * The provider turns an entity query into a list prop. The thing that goes
 * silently wrong is cacheability: without the queried entity type's LIST cache
 * tags, a rendered listing never refreshes when content is added or removed —
 * a stale page with no error anywhere. The tags must therefore be attached
 * whether or not the query matched anything, because "no results yet" is
 * exactly the state that has to invalidate when the first match appears.
 *
 * The shared-reference filter adds a second family: matching entities that
 * point at some of the same targets as the host, combining several such pairs
 * with OR or AND, ranking the survivors by how strongly they match, and
 * leaving the host out of its own results.
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
    // The shared targets: a separate type, so a target id can never be
    // confused with a candidate id.
    $this->installEntitySchema('entity_test_rev');
    $this->installEntitySchema('user');

    // Two multi-value reference fields, standing in for the "related markets"
    // and "related services" pair the filter exists to serve.
    foreach (['field_topics', 'field_tags'] as $fieldName) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'entity_test',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'entity_test_rev'],
        'cardinality' => -1,
      ])->save();
      FieldConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'entity_test',
        'bundle' => 'entity_test',
        'label' => $fieldName,
      ])->save();
    }
  }

  /**
   * Builds the fixture component with the query provider on `items`.
   *
   * @param array $settings
   *   Provider settings merged over the defaults, so each test states only the
   *   keys it cares about.
   *
   * @return \Drupal\neo_alchemist\Entity\Component
   *   The reloaded component.
   */
  private function buildComponent(array $settings = []): Component {
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
          // The bundle is set as real configuration sets it. entity_test has
          // no bundle entity type, so a bundle-less lookup sees base fields
          // only and the query side of a pair would list nothing.
          'settings' => $settings + [
            'entity_type' => 'entity_test',
            'bundle' => 'entity_test',
            'length' => 10,
          ],
        ],
      ])
      ->save();
    $storage->resetCache(['na_array_required']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_array_required');
    return $component;
  }

  /**
   * The children-match mapping that makes `items` resolve to real rows.
   *
   * The fixture's `title` and `count` children are both required, so an item
   * with either unmapped is dropped whole and the prop reads empty.
   */
  private function shapeFields(): array {
    return [
      'title' => ['field' => 'name'],
      'count' => ['field' => 'id'],
    ];
  }

  /**
   * Creates the named shared targets.
   *
   * @return array<string, \Drupal\entity_test\Entity\EntityTestRev>
   *   The targets, keyed by name.
   */
  private function targets(string ...$names): array {
    $targets = [];
    foreach ($names as $name) {
      $target = EntityTestRev::create(['name' => $name]);
      $target->save();
      $targets[$name] = $target;
    }
    return $targets;
  }

  /**
   * Creates a saved candidate entity.
   *
   * @param string $name
   *   The entity name, which the assertions read back as the item title.
   * @param array $refs
   *   Field name => list of target entities.
   */
  private function candidate(string $name, array $refs = []): EntityTest {
    $values = ['name' => $name];
    foreach ($refs as $fieldName => $targets) {
      $values[$fieldName] = array_map(static fn ($target) => $target->id(), $targets);
    }
    $entity = EntityTest::create($values);
    $entity->save();
    return $entity;
  }

  /**
   * Creates a saved host entity and binds it as the component's target.
   */
  private function bindHost(Component $component, array $refs = []): EntityTest {
    $host = $this->candidate('HOST', $refs);
    $this->assertTrue($component->setTargetPreviewEntity((string) $host->id()), 'The saved host binds as the target entity.');
    return $host;
  }

  /**
   * The resolved item titles, in render order.
   *
   * @return string[]
   *   The titles.
   */
  private function itemTitles(Component $component): array {
    $values = $component->getPropValues();
    return array_map(
      static fn (array $item) => (string) ($item['title'] ?? ''),
      $values['items'] ?? [],
    );
  }

  /**
   * A single-pair shared filter on `field_topics`.
   *
   * Excludes the host by default: the host shares every one of its own
   * targets, so it would otherwise top every ranking assertion.
   */
  private function sharedOnTopics(array $extra = []): array {
    return $extra + [
      'shape_fields' => $this->shapeFields(),
      'filter_shared' => [
        ['host' => 'field_topics:entity', 'query' => 'field_topics:entity'],
      ],
      'filter_exclude_self' => TRUE,
    ];
  }

  /**
   * A two-pair shared filter on `field_topics` and `field_tags`.
   */
  private function sharedOnBoth(array $extra = []): array {
    return $extra + [
      'shape_fields' => $this->shapeFields(),
      'filter_shared' => [
        ['host' => 'field_topics:entity', 'query' => 'field_topics:entity'],
        ['host' => 'field_tags:entity', 'query' => 'field_tags:entity'],
      ],
      'filter_exclude_self' => TRUE,
    ];
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

  /**
   * Only entities sharing a target with the host come back.
   */
  public function testSharedReferenceMatchesOverlappingEntity(): void {
    $targets = $this->targets('T1', 'T2');
    $component = $this->buildComponent($this->sharedOnTopics());
    $this->bindHost($component, ['field_topics' => [$targets['T1']]]);
    $this->candidate('SHARES', ['field_topics' => [$targets['T1']]]);
    $this->candidate('DIFFERENT', ['field_topics' => [$targets['T2']]]);
    $this->candidate('UNTAGGED');

    $this->assertSame(
      ['SHARES'],
      $this->itemTitles($component),
      'Only the entity pointing at one of the host’s targets matches.',
    );
  }

  /**
   * An empty host field drops the filter instead of matching nothing.
   *
   * The deliberate degrade: an article that has not been tagged yet should
   * fall back to the plain sorted listing, not render an empty section. This
   * is why resolveSharedReferenceIds() returns NULL rather than [] when no
   * configured pair could contribute.
   */
  public function testSharedReferenceWithEmptyHostFieldDoesNotFilter(): void {
    $targets = $this->targets('T1', 'T2');
    $component = $this->buildComponent($this->sharedOnTopics());
    $this->bindHost($component);
    $this->candidate('A', ['field_topics' => [$targets['T1']]]);
    $this->candidate('B', ['field_topics' => [$targets['T2']]]);
    $this->candidate('C');

    $titles = $this->itemTitles($component);
    sort($titles);
    $this->assertSame(
      ['A', 'B', 'C'],
      $titles,
      'With nothing to match on the results are unfiltered, not empty.',
    );
  }

  /**
   * A host field that matches nothing returns nothing.
   *
   * The other half of the NULL/[] distinction: here a pair did contribute, so
   * "nothing matched" is a real answer and must not degrade to everything.
   */
  public function testSharedReferenceMatchingNothingReturnsNothing(): void {
    $targets = $this->targets('T1', 'ORPHAN');
    $component = $this->buildComponent($this->sharedOnTopics());
    $this->bindHost($component, ['field_topics' => [$targets['ORPHAN']]]);
    $this->candidate('A', ['field_topics' => [$targets['T1']]]);
    $this->candidate('B', ['field_topics' => [$targets['T1']]]);

    $this->assertSame([], $this->itemTitles($component), 'A contributing pair that matches nothing returns nothing.');
  }

  /**
   * OR returns entities matching any configured pair.
   */
  public function testSharedReferenceOrCombinesPairs(): void {
    $targets = $this->targets('T1', 'T2', 'G1');
    $component = $this->buildComponent($this->sharedOnBoth(['filter_shared_operator' => 'or']));
    $this->bindHost($component, [
      'field_topics' => [$targets['T1']],
      'field_tags' => [$targets['G1']],
    ]);
    $this->candidate('TOPIC_ONLY', ['field_topics' => [$targets['T1']]]);
    $this->candidate('TAG_ONLY', ['field_tags' => [$targets['G1']]]);
    $this->candidate('NEITHER', ['field_topics' => [$targets['T2']]]);

    $titles = $this->itemTitles($component);
    sort($titles);
    $this->assertSame(['TAG_ONLY', 'TOPIC_ONLY'], $titles, 'Matching either pair is enough under OR.');
  }

  /**
   * AND requires every contributing pair to match.
   */
  public function testSharedReferenceAndIntersectsPairs(): void {
    $targets = $this->targets('T1', 'G1');
    $component = $this->buildComponent($this->sharedOnBoth(['filter_shared_operator' => 'and']));
    $this->bindHost($component, [
      'field_topics' => [$targets['T1']],
      'field_tags' => [$targets['G1']],
    ]);
    $this->candidate('TOPIC_ONLY', ['field_topics' => [$targets['T1']]]);
    $this->candidate('TAG_ONLY', ['field_tags' => [$targets['G1']]]);
    $this->candidate('BOTH', [
      'field_topics' => [$targets['T1']],
      'field_tags' => [$targets['G1']],
    ]);

    $this->assertSame(['BOTH'], $this->itemTitles($component), 'Under AND only an entity matching every pair survives.');
  }

  /**
   * Under AND an empty host field abstains rather than vetoing.
   *
   * Otherwise "this page has no tags yet" would silently mean "show nothing".
   */
  public function testSharedReferenceAndIgnoresEmptyHostField(): void {
    $targets = $this->targets('T1', 'G1');
    $component = $this->buildComponent($this->sharedOnBoth(['filter_shared_operator' => 'and']));
    // No field_tags on the host, so only the topics pair can contribute.
    $this->bindHost($component, ['field_topics' => [$targets['T1']]]);
    $this->candidate('TOPIC_ONLY', ['field_topics' => [$targets['T1']]]);
    $this->candidate('TAG_ONLY', ['field_tags' => [$targets['G1']]]);

    $this->assertSame(
      ['TOPIC_ONLY'],
      $this->itemTitles($component),
      'A pair whose host field is empty never became a source, so it cannot veto.',
    );
  }

  /**
   * The range counts entities, not matched field deltas.
   *
   * The regression this whole design exists for. A condition on a multi-value
   * reference field joins the field table, so an entity sharing three targets
   * with the host produces three rows — and Query::finish() applies range()
   * before Query::result()'s fetchAllKeyed() collapses them. Implemented as a
   * join, a `length: 3` listing whose first hit shares three targets renders
   * ONE item. Resolving to an id set first cannot multiply rows.
   *
   * @see \Drupal\Core\Entity\Query\Sql\Query::finish()
   * @see \Drupal\Core\Entity\Query\Sql\Query::result()
   */
  public function testSharedReferenceRangeCountsDistinctEntities(): void {
    $targets = $this->targets('T1', 'T2', 'T3');
    $all = array_values($targets);
    $component = $this->buildComponent($this->sharedOnTopics(['length' => 3]));
    $this->bindHost($component, ['field_topics' => $all]);
    $this->candidate('A', ['field_topics' => $all]);
    $this->candidate('B', ['field_topics' => $all]);
    $this->candidate('C', ['field_topics' => $all]);

    $titles = $this->itemTitles($component);
    sort($titles);
    $this->assertSame(
      ['A', 'B', 'C'],
      $titles,
      'Three entities each sharing three targets fill three slots, not one.',
    );
  }

  /**
   * Ranking prefers matching more pairs over sharing more targets.
   *
   * "Shares a market AND a service" beats "shares two markets", which beats
   * "shares one market" — regardless of the configured sort, which only breaks
   * ties.
   */
  public function testRankingPrefersBreadthOverDepth(): void {
    $targets = $this->targets('T1', 'T2', 'G1');
    $component = $this->buildComponent($this->sharedOnBoth([
      // Newest first, so date alone would invert the expected order.
      'sort_field' => 'id',
      'sort_direction' => 'DESC',
      'filter_shared_rank' => TRUE,
    ]));
    $this->bindHost($component, [
      'field_topics' => [$targets['T1'], $targets['T2']],
      'field_tags' => [$targets['G1']],
    ]);
    // Created oldest first; the ranking has to override the id DESC sort.
    $this->candidate('BREADTH_2', [
      'field_topics' => [$targets['T1']],
      'field_tags' => [$targets['G1']],
    ]);
    $this->candidate('DEPTH_2', ['field_topics' => [$targets['T1'], $targets['T2']]]);
    $this->candidate('DEPTH_1', ['field_topics' => [$targets['T1']]]);

    $this->assertSame(
      ['BREADTH_2', 'DEPTH_2', 'DEPTH_1'],
      $this->itemTitles($component),
      'Two pairs outrank two shared targets in one pair, which outranks one.',
    );
  }

  /**
   * Equally-scored candidates keep the configured sort order.
   */
  public function testRankingFallsBackToSortOnTies(): void {
    $targets = $this->targets('T1');
    $component = $this->buildComponent($this->sharedOnTopics([
      'sort_field' => 'id',
      'sort_direction' => 'DESC',
      'filter_shared_rank' => TRUE,
    ]));
    $this->bindHost($component, ['field_topics' => [$targets['T1']]]);
    $this->candidate('OLDER', ['field_topics' => [$targets['T1']]]);
    $this->candidate('NEWER', ['field_topics' => [$targets['T1']]]);

    $this->assertSame(
      ['NEWER', 'OLDER'],
      $this->itemTitles($component),
      'With identical scores the configured sort decides, so the ranking sort must be stable.',
    );
  }

  /**
   * An unranked query renders in its configured sort order.
   *
   * The load step must not reshuffle the query's answer. Core's loadMultiple()
   * returns entities in the order the ids were passed, which the ranking in
   * getChildrenMatchEntities() relies on to survive loading — so this pins the
   * contract rather than a local behaviour.
   *
   * @see \Drupal\Core\Entity\EntityStorageBase::loadMultiple()
   */
  public function testUnrankedQueryPreservesSortOrder(): void {
    $component = $this->buildComponent([
      'shape_fields' => $this->shapeFields(),
      'sort_field' => 'id',
      'sort_direction' => 'DESC',
    ]);
    $this->candidate('FIRST');
    $this->candidate('SECOND');
    $this->candidate('THIRD');

    $this->assertSame(
      ['THIRD', 'SECOND', 'FIRST'],
      $this->itemTitles($component),
      'The configured sort survives the load step.',
    );
  }

  /**
   * The host is left out of its own results.
   */
  public function testExcludeSelfDropsTheHostEntity(): void {
    $component = $this->buildComponent([
      'shape_fields' => $this->shapeFields(),
      'filter_exclude_self' => TRUE,
    ]);
    $this->bindHost($component);
    $this->candidate('A');
    $this->candidate('B');

    $titles = $this->itemTitles($component);
    sort($titles);
    $this->assertSame(['A', 'B'], $titles, 'The entity the component renders on is not in its own list.');
  }

  /**
   * On an unsaved host the exclusion is inert rather than matching nothing.
   */
  public function testExcludeSelfIsInertOnAnUnsavedHost(): void {
    $component = $this->buildComponent([
      'shape_fields' => $this->shapeFields(),
      'filter_exclude_self' => TRUE,
    ]);
    $this->candidate('A');
    $this->candidate('B');

    $titles = $this->itemTitles($component);
    sort($titles);
    $this->assertSame(['A', 'B'], $titles, 'A placeholder host has no id to exclude, so nothing is dropped.');
  }

  /**
   * The host's cache tag reaches the component when its fields drive the query.
   *
   * The results now vary by the host's own field values, which change without
   * its id changing — so without this the listing keeps showing yesterday's
   * related items after the host is re-tagged.
   */
  public function testHostFieldValuesReachCacheability(): void {
    $targets = $this->targets('T1');
    $component = $this->buildComponent($this->sharedOnTopics());
    $host = $this->bindHost($component, ['field_topics' => [$targets['T1']]]);
    $this->candidate('A', ['field_topics' => [$targets['T1']]]);
    $component->getPropValues();

    $this->assertContains(
      'entity_test:' . $host->id(),
      $component->getCacheableMetadata()->getCacheTags(),
      'Re-tagging the host invalidates the listing its tags produced.',
    );
  }

  /**
   * Both sides of a pair are offered, and the operator stores as a sibling.
   */
  public function testSharedReferenceFormPairsBothSides(): void {
    $component = $this->buildComponent($this->sharedOnTopics());
    $plugin = $component->getPropShapes()['items']->getValueCollection()->get('entity_query');
    $complete = [];
    $form = $plugin->buildConfigurationForm(['#parents' => ['settings']], new FormState(), $complete);

    $this->assertArrayHasKey('filter_shared', $form, 'The shared-reference filter renders.');
    $hostOptions = [];
    foreach ($form['filter_shared'][0]['host']['#options'] as $group) {
      $hostOptions += is_array($group) ? $group : [];
    }
    $this->assertArrayHasKey('field_topics:entity', $hostOptions, 'The host side lists the host entity’s reference fields.');
    $this->assertSame(
      ['settings', 'filter_shared_operator'],
      $form['filter_shared']['operator']['#parents'],
      'The operator is rendered inside the details but stored as a sibling key.',
    );
  }

  /**
   * Pairs whose two sides point at different entity types are rejected.
   *
   * This is an error, not a warning: the filter compares raw target ids, so a
   * mismatched pair does not return nothing — it returns whichever entity
   * happens to carry a colliding id.
   */
  public function testMismatchedReferenceTargetsAreRejected(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_self',
      'entity_type' => 'entity_test',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'entity_test'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_self',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Self',
    ])->save();

    $component = $this->buildComponent($this->sharedOnTopics());
    $plugin = $component->getPropShapes()['items']->getValueCollection()->get('entity_query');
    $formState = new FormState();
    $complete = [];
    $form = $plugin->buildConfigurationForm(['#parents' => ['settings']], $formState, $complete);
    $formState->setValue('filter_shared', [
      0 => ['host' => 'field_self:entity', 'query' => 'field_topics:entity'],
    ]);
    $plugin->validateConfigurationForm($form, $formState);

    $this->assertNotEmpty($formState->getErrors(), 'A pair whose sides target different entity types is an error.');
  }

  /**
   * Incomplete rows never reach stored configuration.
   */
  public function testIncompleteRowsAreNotStored(): void {
    $component = $this->buildComponent($this->sharedOnTopics());
    $plugin = $component->getPropShapes()['items']->getValueCollection()->get('entity_query');

    $massaged = $plugin->massageFormValue([
      'filter_shared' => [
        ['host' => 'field_topics:entity', 'query' => 'field_topics:entity'],
        ['host' => '', 'query' => ''],
        ['host' => 'field_tags:entity', 'query' => ''],
      ],
    ], [], new FormState());

    $this->assertSame(
      [['host' => 'field_topics:entity', 'query' => 'field_topics:entity']],
      $massaged['filter_shared'],
      'Only complete rows are stored, re-indexed from zero.',
    );
  }

}
