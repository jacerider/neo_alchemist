<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Shape\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests aggregate mode — the whole schema wrapped in one `_aggregate` prop.
 *
 * A component with `aggregate: TRUE` exists to be filled from ONE source: the
 * props schema is wrapped in a synthetic object prop so a single children-match
 * provider (entity_query, views, …) can bind every prop at once, instead of the
 * same provider being configured eight times over.
 *
 * The wrap is invisible at the render boundary and total everywhere else, which
 * is what makes it worth pinning: the SDC must still receive the flat prop set
 * it declared, while shapes, settings keys and prop routes all collapse to the
 * single `_aggregate` name.
 *
 * @see \Drupal\neo_alchemist\Entity\Component::getAggregateSchema()
 * @see \Drupal\neo_alchemist\Form\ComponentAggregateForm
 */
#[Group('neo_alchemist')]
class AggregateModeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    // The fixture's `link` prop stores into a link field item whose enum'd
    // `target` child resolves to a list_string item, so both field types must
    // exist. Same pair ObjectShapeFalsyValueTest needs for this fixture.
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
    $this->installEntitySchema('user');
  }

  /**
   * Creates a fixture component with three top-level props.
   *
   * Component::save() re-derives a new component's id from its SDC id, so the
   * id is read back rather than assumed.
   */
  private function createComponent(bool $aggregate): Component {
    $component = Component::create([
      'label' => 'Aggregate fixture',
      'description' => 'Aggregate fixture',
      'component' => 'neo_alchemist_test:na_falsy_object',
      'status' => TRUE,
      'aggregate' => $aggregate,
      'target_entity_type' => 'entity_test',
    ]);
    $component->save();
    return $this->reload($component->id());
  }

  /**
   * Reloads the component, dropping every cached shape.
   */
  private function reload(string $id): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $storage->resetCache([$id]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($id);
    return $component;
  }

  /**
   * The stored `settings.props` keys.
   */
  private function propKeys(string $id): array {
    $props = $this->container->get('config.factory')
      ->get('neo_alchemist.neo_component.' . $id)
      ->get('settings.props') ?? [];
    $keys = array_keys($props);
    sort($keys);
    return $keys;
  }

  /**
   * Aggregating collapses every prop shape into one `_aggregate` object.
   */
  public function testSchemaIsWrappedInSingleProp(): void {
    $plain = $this->createComponent(FALSE);
    $this->assertSame(
      ['box', 'count', 'link'],
      $this->sortedKeys($plain->getPropShapes()),
      'Without aggregation each schema prop gets its own shape.',
    );

    $aggregated = $this->createComponent(TRUE);
    $this->assertSame(
      ['_aggregate'],
      $this->sortedKeys($aggregated->getPropShapes()),
      'Aggregating leaves exactly one root shape, which is what makes a single provider able to bind everything.',
    );
  }

  /**
   * The real props survive as children of the synthetic object.
   *
   * They are reached through the children-match "Shape Fields" UI rather than
   * each owning a prop form, so losing them here would leave nothing bindable.
   */
  public function testRealPropsBecomeChildrenOfTheAggregate(): void {
    $component = $this->createComponent(TRUE);
    $aggregate = $component->getPropShape('_aggregate');

    $this->assertInstanceOf(ComponentShapeChildrenPluginInterface::class, $aggregate);
    $this->assertSame(['box', 'count', 'link'], $this->sortedKeys($aggregate->getChildShapes()));
  }

  /**
   * The SDC still receives the flat prop set it declared.
   *
   * The wrap is an authoring-side construct. If `_aggregate` reached the render
   * boundary, SDC would reject the props against the component's own schema and
   * every aggregated component would fail to render.
   */
  public function testRenderedPropsAreUnwrapped(): void {
    $component = $this->createComponent(TRUE);
    $values = $component->getPropValues();

    $this->assertArrayNotHasKey('_aggregate', $values, 'The synthetic wrapper never reaches SDC.');
    $this->assertArrayHasKey('box', $values, 'The declared props are handed over exactly as the schema names them.');
    $this->assertSame('EXAMPLE TEXT', $values['box']['text'] ?? NULL);
  }

  /**
   * The `_aggregate` name resolves as a shape but is not a schema property.
   *
   * ComponentPropAccessCheck special-cases the name for this reason — the
   * generic branch tests `getComponentSchema()['properties']`, which will never
   * contain it, so without the special case the prop form 404s.
   *
   * @see \Drupal\neo_alchemist\Access\ComponentPropAccessCheck::access()
   */
  public function testAggregatePropResolvesButIsNotInTheSchema(): void {
    $component = $this->createComponent(TRUE);

    $this->assertNotNull($component->getPropShape('_aggregate'));
    $this->assertArrayNotHasKey('_aggregate', $component->getComponentSchema()['properties']);
  }

  /**
   * Per-prop settings cannot be written back while aggregating.
   *
   * The guard keeps a stray save from reintroducing per-prop settings alongside
   * `_aggregate`, which would leave two competing sources of truth for the same
   * prop.
   *
   * @see \Drupal\neo_alchemist\Entity\Component::setPropShapeSettings()
   */
  public function testChildShapeSettingsAreNotPersistedWhileAggregating(): void {
    $component = $this->createComponent(TRUE);
    $aggregate = $component->getPropShape('_aggregate');
    $this->assertInstanceOf(ComponentShapeChildrenPluginInterface::class, $aggregate);
    $child = $aggregate->getChildShapes()['count'];

    $component->setPropShapeSettings($child);
    $component->save();

    $this->assertSame(['_aggregate'], $this->propKeys($component->id()));
  }

  /**
   * Toggling aggregation discards prop value settings, in both directions.
   *
   * Not an endorsement — a characterization test. Switching changes the prop
   * set, so the generated expression changes, so preSave() takes its
   * `setSetting('props', [])` rebuild branch and nothing is carried across. The
   * confirm form warns about exactly this; if the behavior is ever made
   * non-destructive, this test is the one that should fail and be rewritten.
   *
   * @see \Drupal\neo_alchemist\Entity\Component::preSave()
   * @see \Drupal\neo_alchemist\Form\ComponentAggregateForm::getDescription()
   */
  public function testTogglingDiscardsPropSettings(): void {
    $component = $this->createComponent(FALSE);
    $id = $component->id();

    // A real, persisted, non-default per-prop setting.
    $component->setPropShapeSettings($component->getPropShape('count')->setEditable(FALSE));
    $component->save();
    $this->assertFalse(
      $this->container->get('config.factory')->get('neo_alchemist.neo_component.' . $id)->get('settings.props.count.editable'),
      'Precondition: the per-prop setting persists across an ordinary save.',
    );

    // Enable: every per-prop setting goes.
    $component = $this->reload($id);
    $component->set('aggregate', TRUE)->save();
    $this->assertSame(['_aggregate'], $this->propKeys($id));

    // Disable: the prop keys come back, rebuilt from schema defaults, and the
    // aggregate configuration is gone with them.
    $component = $this->reload($id);
    $component->set('aggregate', FALSE)->save();
    $this->assertSame(['box', 'count', 'link'], $this->propKeys($id));
    $this->assertTrue(
      $this->container->get('config.factory')->get('neo_alchemist.neo_component.' . $id)->get('settings.props.count.editable'),
      'The round trip does NOT restore the setting — it is rebuilt from the schema default.',
    );
  }

  /**
   * The confirm form names what the switch will discard.
   *
   * The warning exists to stop an exploratory click from destroying real work,
   * so it has to name that work. It also has to stay quiet when there is none —
   * a warning that fires on every component is one nobody reads, which is the
   * whole reason shape `default_plugins` are excluded from the count.
   *
   * @see \Drupal\neo_alchemist\Form\ComponentAggregateForm::getDescription()
   */
  public function testConfirmFormNamesWhatIsDiscarded(): void {
    // Nothing configured: no alarm. Every plugin the fixture's shapes carry is
    // scaffolding they ship themselves.
    $plain = $this->createComponent(FALSE);
    $this->assertStringContainsString(
      'No value providers are configured yet',
      (string) $this->describe($plain),
      'A component with only default plugins must not claim work is at risk.',
    );

    // Aggregated with a provider bound: the provider is named.
    $aggregated = $this->createComponent(TRUE);
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.' . $aggregated->id())
      ->set('settings.props._aggregate.plugins._aggregate', [
        'entity_query' => [
          'id' => 'entity_query',
          'settings' => ['entity_type' => 'entity_test', 'length' => 10],
        ],
      ])
      ->save();
    $description = (string) $this->describe($this->reload($aggregated->id()));

    $this->assertStringContainsString('Entity Query', $description);
    $this->assertStringContainsString('cannot be undone', $description);
  }

  /**
   * The confirm form's description for a component.
   */
  private function describe(Component $component) {
    $form = $this->container->get('entity_type.manager')
      ->getFormObject('neo_component', 'aggregate');
    $form->setEntity($component);
    return $form->getDescription();
  }

  /**
   * Sorted keys of a shape array.
   */
  private function sortedKeys(array $shapes): array {
    $keys = array_keys($shapes);
    sort($keys);
    return $keys;
  }

}
