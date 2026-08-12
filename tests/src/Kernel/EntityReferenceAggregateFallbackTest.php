<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * The primary-reference / query-fallback chain on an aggregate component.
 *
 * The recipe this pins: an aggregated component sources its props from an
 * entity reference field on the host entity when that field is filled
 * (entity_reference, stop_when_found), and otherwise falls back to an entity
 * query (block). Four ARRAY props already used the chain; on the `_aggregate`
 * OBJECT shape it was impossible — entity_reference declared
 * `prop_types: [ARRAY]`, so the plugin was never offered there and a saved
 * instance was built out of the collection entirely, silently.
 *
 * Also covered: the dangling-reference guard (a reference whose targets no
 * longer load must fall through, not claim a map of empties), and the
 * aggregate-aware prop_value access rule that hides the component when both
 * sources come up empty.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\EntityReferenceValue
 * @see \Drupal\neo_alchemist\Plugin\ComponentAccess\PropValueAccess
 */
#[Group('neo_alchemist')]
class EntityReferenceAggregateFallbackTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'options',
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
    // The source type is deliberately different from the host type, so the
    // fallback query can never accidentally match the host itself.
    $this->installEntitySchema('entity_test_rev');
    $this->installEntitySchema('user');

    FieldStorageConfig::create([
      'field_name' => 'field_ref',
      'entity_type' => 'entity_test',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'entity_test_rev'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_ref',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Reference',
    ])->save();
  }

  /**
   * The children-match mapping both providers share.
   */
  private function shapeFields(): array {
    return [
      'box' => [
        'field' => '_expand',
        'shape_fields' => ['text' => ['field' => 'name']],
      ],
    ];
  }

  /**
   * Builds an aggregated component with the reference-above-query chain.
   *
   * @param string $sdc
   *   The fixture SDC id.
   * @param array $shapeFields
   *   The children-match mapping, shared verbatim by both providers — as the
   *   copy-mapping convenience produces.
   * @param array|null $accessProps
   *   Props for a prop_value access rule, or NULL for none.
   *
   * @return \Drupal\neo_alchemist\Entity\Component
   *   The reloaded component.
   */
  private function buildComponent(string $sdc = 'na_falsy_object', ?array $shapeFields = NULL, ?array $accessProps = NULL): Component {
    $shapeFields ??= $this->shapeFields();
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $component = Component::create([
      'label' => 'Aggregate fallback fixture',
      'description' => 'Aggregate fallback fixture',
      'component' => 'neo_alchemist_test:' . $sdc,
      'status' => TRUE,
      'aggregate' => TRUE,
      'target_entity_type' => 'entity_test',
    ]);
    $component->save();
    $id = $component->id();

    $config = $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.' . $id)
      // Intra-group order is key order: the reference is the primary source,
      // the query the fallback.
      ->set('settings.props._aggregate.plugins._aggregate', [
        'entity_reference' => [
          'id' => 'entity_reference',
          'settings' => [
            'entity' => 'field_ref:entity',
            'shape_fields' => $shapeFields,
            'shape_published' => TRUE,
            'processing_mode' => 'stop_when_found',
          ],
        ],
        'entity_query' => [
          'id' => 'entity_query',
          'settings' => [
            'entity_type' => 'entity_test_rev',
            'sort_field' => 'id',
            'sort_direction' => 'DESC',
            'length' => 1,
            'shape_fields' => $shapeFields,
            'processing_mode' => 'block',
          ],
        ],
      ]);
    if ($accessProps !== NULL) {
      $config->set('settings.access', [
        'test-prop-value' => [
          'plugin_id' => 'prop_value',
          'plugin_settings' => ['props' => $accessProps],
        ],
      ]);
    }
    $config->save();
    $storage->resetCache([$id]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($id);
    return $component;
  }

  /**
   * Creates a saved host entity and binds it as the component's target.
   */
  private function bindHost(Component $component, array $values = []): EntityTest {
    $host = EntityTest::create(['name' => 'HOST'] + $values);
    $host->save();
    $this->assertTrue($component->setTargetPreviewEntity((string) $host->id()), 'The saved host binds as the target entity.');
    return $host;
  }

  /**
   * The resolved `box.text` value of the unwrapped props.
   */
  private function boxText(Component $component): mixed {
    $values = $component->getPropValues();
    return $values['box']['text'][0]['value'] ?? $values['box']['text'] ?? NULL;
  }

  /**
   * The offer regression: entity_reference is available on `_aggregate`.
   *
   * The getValueCollection() method builds instances only from the filtered
   * definition list, so a prop_types miss does not merely hide the plugin
   * from the form — it silently drops a saved instance from the pipeline.
   */
  public function testEntityReferenceIsOfferedOnTheAggregateShape(): void {
    $component = $this->buildComponent();

    $definitions = $this->container->get('plugin.manager.neo_component_value')
      ->getFilteredDefinitionsFromShape($component->getPropShape('_aggregate'));

    $this->assertArrayHasKey('entity_reference', $definitions, 'The provider is offered on an aggregate object shape.');
  }

  /**
   * A filled reference wins, even over a newer query candidate.
   */
  public function testFilledReferenceBeatsTheQuery(): void {
    $ref = EntityTestRev::create(['name' => 'REF NAME']);
    $ref->save();
    // Newer, so the query would return it if it ran.
    EntityTestRev::create(['name' => 'QUERY NAME'])->save();

    $component = $this->buildComponent();
    $this->bindHost($component, ['field_ref' => [$ref->id()]]);

    $this->assertSame('REF NAME', $this->boxText($component), 'The reference claims; the fallback never runs.');
  }

  /**
   * An empty reference falls through to the query fallback.
   */
  public function testEmptyReferenceFallsBackToTheQuery(): void {
    EntityTestRev::create(['name' => 'OLD NAME'])->save();
    EntityTestRev::create(['name' => 'QUERY NAME'])->save();

    $component = $this->buildComponent();
    $this->bindHost($component);

    $this->assertSame('QUERY NAME', $this->boxText($component), 'The newest query result supplies the values.');
  }

  /**
   * With both sources empty, nothing renders — the example does not leak.
   */
  public function testBothSourcesEmptyRendersNothing(): void {
    $component = $this->buildComponent();
    $this->bindHost($component);

    $values = $component->getPropValues();

    $this->assertStringNotContainsString('EXAMPLE TEXT', json_encode($values), 'The schema example never leaks past a block claim.');
    $this->assertEmpty($values['box']['text'] ?? NULL, 'The mapped child resolves to nothing.');
  }

  /**
   * A dangling reference behaves as an empty one: the fallback runs.
   *
   * What makes this safe today is MatcherReference::getReferenceField(),
   * which returns NULL whenever the first target fails to load — the plugin
   * then provides a truly-empty value and stop_when_found falls through. The
   * hazard this pins against: feeding zero entities to the children matcher
   * on a non-iterable shape returns per-child empties that the emptiness
   * contract counts as a VALUE, which would claim — starving the fallback and
   * force-hiding every child. The plugin carries its own zero-entities guard
   * as defense-in-depth, and this test holds the behavior whichever layer
   * provides it.
   */
  public function testDanglingReferenceFallsBack(): void {
    $ref = EntityTestRev::create(['name' => 'REF NAME']);
    $ref->save();
    EntityTestRev::create(['name' => 'QUERY NAME'])->save();

    $component = $this->buildComponent();
    $this->bindHost($component, ['field_ref' => [$ref->id()]]);
    $ref->delete();

    $this->assertSame('QUERY NAME', $this->boxText($component), 'A reference whose targets are gone behaves as an empty reference.');
  }

  /**
   * The prop_value access rule hides the component when both sources fail.
   *
   * Also pins the aggregate-aware option list: the rule form must offer the
   * aggregate's children (the keys ::access() actually finds in the unwrapped
   * getPropValues()), not the synthetic `_aggregate` prop, which never appears
   * there and could only ever produce a rule that always forbids.
   */
  public function testPropValueAccessGatesOnAggregateChildren(): void {
    EntityTestRev::create(['name' => 'QUERY NAME'])->save();

    $component = $this->accessGatedComponent();
    $this->bindHost($component);

    $instance = array_values($component->getAccessInstances())[0];

    // The form offers child props, not the synthetic wrapper.
    $complete = [];
    $form = $instance->getPlugin()->buildConfigurationForm([], new FormState(), $complete);
    $this->assertArrayHasKey('text', $form['props']['#options']);
    $this->assertArrayNotHasKey('_aggregate', $form['props']['#options']);

    // Fallback supplies a value: visible.
    $this->assertFalse($instance->access('view', $this->container->get('current_user'))->isForbidden(), 'With a query hit the component is visible.');
  }

  /**
   * The same rule forbids view when both sources are empty.
   */
  public function testPropValueAccessForbidsWhenBothSourcesEmpty(): void {
    $component = $this->accessGatedComponent();
    $this->bindHost($component);

    $instance = array_values($component->getAccessInstances())[0];

    $this->assertTrue($instance->access('view', $this->container->get('current_user'))->isForbidden(), 'With both sources empty the component is hidden.');
  }

  /**
   * A component whose visibility is gated on its mapped string prop.
   *
   * Uses na_match_probe rather than na_falsy_object: the gate must sit on a
   * prop that resolves truly empty when no source provides. The falsy fixture's
   * children exist to pin the opposite — FALSE and 0 are values — so its
   * object prop is never absent from the resolved values and could never
   * gate anything.
   */
  private function accessGatedComponent(): Component {
    return $this->buildComponent(
      'na_match_probe',
      ['text' => ['field' => 'name']],
      ['text'],
    );
  }

}
