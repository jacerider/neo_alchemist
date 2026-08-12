<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\entity_test\Entity\EntityTestBundle;
use Drupal\entity_test\Entity\EntityTestWithBundle;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Form\ComponentPropForm;
use PHPUnit\Framework\Attributes\Group;

/**
 * The prop form's list↔edit provider UI, prop-first tabs and mapping table.
 *
 * The form was rebuilt from an "every applicable plugin is a table row with
 * its settings inline" layout to: only ACTIVE providers listed as summary
 * rows, an Add select for the rest, one provider's settings open at a time,
 * one vertical tab per shape, and the children-match mapping as a table. On a
 * real aggregate component the old layout rendered ~68 provider rows and over
 * a megabyte of HTML.
 *
 * Also pinned here, because it broke silently during the rebuild: a button's
 * #limit_validation_errors defaults to FALSE (meaning "validate everything"),
 * so limited-submission detection must test for an ARRAY — testing for key
 * presence classifies every button as limited, which made Update and Save
 * skip the commit path entirely while still reporting success.
 *
 * @see \Drupal\neo_alchemist\Form\ComponentPropForm
 */
#[Group('neo_alchemist')]
class ComponentPropFormUxTest extends KernelTestBase {

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
    $this->installEntitySchema('entity_test_with_bundle');
    $this->installEntitySchema('entity_test_rev');
    $this->installEntitySchema('user');

    // A bundle-bearing host: the reference matcher resolves configurable
    // fields only through bundles, so a bundle-less entity_test host would
    // hide field_ref from the provider form entirely.
    EntityTestBundle::create(['id' => 'main', 'label' => 'Main'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_ref',
      'entity_type' => 'entity_test_with_bundle',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'entity_test_rev'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_ref',
      'entity_type' => 'entity_test_with_bundle',
      'bundle' => 'main',
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
   * An aggregate component with the reference-above-query provider chain.
   *
   * @param array $extra
   *   Extra keys to merge into settings.props._aggregate.
   *
   * @return \Drupal\neo_alchemist\Entity\Component
   *   The reloaded component.
   */
  private function buildComponent(array $extra = []): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $component = Component::create([
      'label' => 'Prop form fixture',
      'description' => 'Prop form fixture',
      'component' => 'neo_alchemist_test:na_falsy_object',
      'status' => TRUE,
      'aggregate' => TRUE,
      'target_entity_type' => 'entity_test_with_bundle',
      'target_entity_bundle' => 'main',
    ]);
    $component->save();
    $id = $component->id();

    $config = $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.' . $id)
      ->set('settings.props._aggregate.plugins._aggregate', [
        'entity_reference' => [
          'id' => 'entity_reference',
          'settings' => [
            'entity' => 'field_ref:entity',
            'shape_fields' => $this->shapeFields(),
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
            'shape_fields' => $this->shapeFields(),
            'processing_mode' => 'block',
          ],
        ],
      ]);
    foreach ($extra as $key => $value) {
      $config->set('settings.props._aggregate.' . $key, $value);
    }
    $config->save();
    $storage->resetCache([$id]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($id);
    return $component;
  }

  /**
   * Builds the prop form for a component.
   *
   * @param \Drupal\neo_alchemist\Entity\Component $component
   *   The component.
   * @param \Drupal\Core\Form\FormState|null $formState
   *   A form state, pre-seeded with op state if the edit pane should open.
   * @param \Drupal\neo_alchemist\Form\ComponentPropForm|null $formObject
   *   Returns the form object used, for validateForm() calls.
   *
   * @return array
   *   The processed form.
   */
  private function buildPropForm(Component $component, ?FormState $formState = NULL, ?ComponentPropForm &$formObject = NULL): array {
    /** @var \Drupal\neo_alchemist\Form\ComponentPropForm $formObject */
    $formObject = $this->container->get('entity_type.manager')
      ->getFormObject('neo_component', 'prop');
    $formObject->setEntity($component);
    $formState = $formState ?? new FormState();
    $formState->addBuildInfo('args', ['_aggregate']);
    return $this->container->get('form_builder')->buildForm($formObject, $formState);
  }

  /**
   * A form state whose op targets the entity_reference edit pane.
   */
  private function editOpState(): FormState {
    $formState = new FormState();
    $formState->set('op', 'edit');
    $formState->set('op_shape', '_aggregate');
    $formState->set('op_group', 'providers');
    $formState->set('op_plugin', 'entity_reference');
    $formState->set('op_new', FALSE);
    return $formState;
  }

  /**
   * The list shows only active providers; the Add select offers the rest.
   */
  public function testProviderListShowsOnlyActiveInstances(): void {
    $component = $this->buildComponent();
    $form = $this->buildPropForm($component);

    $section = $form['providers__aggregate'];
    $rows = array_filter(array_keys($section['list']), static fn ($key) => is_string($key) && $key[0] !== '#');
    $this->assertSame(['entity_reference', 'entity_query'], array_values($rows), 'Only the two stored providers are rows.');

    foreach (['entity_reference', 'entity_query'] as $pluginId) {
      $this->assertArrayHasKey('edit', $section['list'][$pluginId]['ops']);
      $this->assertArrayHasKey('remove', $section['list'][$pluginId]['ops']);
      $this->assertArrayNotHasKey('settings', $section['list'][$pluginId], 'No inline settings form in list rows.');
    }

    $addOptions = array_keys($section['add']['#options']);
    $this->assertNotContains('entity_reference', $addOptions, 'Active providers are not re-offered.');
    $this->assertNotContains('entity_query', $addOptions);
    $this->assertNotEmpty($addOptions, 'Inactive applicable providers remain addable.');
  }

  /**
   * List rows carry the plugin's settings summary and its chain-mode badge.
   */
  public function testListRowsCarrySummaryAndChainMode(): void {
    $component = $this->buildComponent();
    $form = $this->buildPropForm($component);

    $context = $form['providers__aggregate']['list']['entity_reference']['label']['#context'];
    $summary = implode(' | ', array_map('strval', $context['summary']));
    $this->assertStringContainsString('field_ref', $summary, 'The source field is named.');
    $this->assertStringContainsString('fields mapped', $summary, 'The mapping count is shown.');
    $this->assertSame('stops when it finds a value', (string) $context['mode']);

    $queryContext = $form['providers__aggregate']['list']['entity_query']['label']['#context'];
    $this->assertSame('always claims — final', (string) $queryContext['mode']);
  }

  /**
   * Expanded props render one vertical tab per shape with badged sections.
   */
  public function testPropFirstTabsAndSectionBadges(): void {
    $component = $this->buildComponent(['expanded' => ['_aggregate~box']]);
    $form = $this->buildPropForm($component);

    $this->assertSame('vertical_tabs', $form['tabs']['#type']);
    $this->assertSame('tabs', $form['shape__aggregate']['#group'], 'The root shape is a tab.');
    $this->assertSame('All properties', (string) $form['shape__aggregate']['#title'], 'The aggregate root is not called "Base".');
    // The old layout rendered one vertical-tabs set per value group.
    $this->assertArrayNotHasKey('providers', $form);
    $this->assertArrayNotHasKey('fallback', $form);

    $childTabs = array_filter(array_keys($form), static fn ($key) => is_string($key) && str_starts_with($key, 'shape_') && $key !== 'shape__aggregate');
    $this->assertNotEmpty($childTabs, 'Each expanded child shape gets its own tab.');

    $providers = $form['shape__aggregate']['providers__aggregate'];
    $this->assertStringContainsString('2 active', (string) $providers['#title'], 'A configured section says how much is active.');
    $this->assertTrue($providers['#open'], 'Configured sections start open.');

    $childTab = $form[reset($childTabs)];
    $emptySection = NULL;
    foreach ($childTab as $key => $element) {
      if (is_string($key) && str_starts_with($key, 'providers_')) {
        $emptySection = $element;
      }
    }
    $this->assertNotNull($emptySection);
    $this->assertStringContainsString('Not configured', (string) $emptySection['#title'], 'An empty section says so.');
    $this->assertFalse($emptySection['#open'], 'Empty sections start collapsed.');
  }

  /**
   * The edit pane renders the children-match mapping as a table.
   *
   * The row and cell levels are presentation only: explicit #parents keep the
   * submitted value tree at settings.shape_fields.<child>.…, so the stored
   * config format is untouched by the layout change. The rarely-used controls
   * (published-only, copy-mapping) live behind Advanced with their original
   * #parents.
   */
  public function testEditPaneRendersMappingTable(): void {
    $component = $this->buildComponent();
    // The children-match form only renders once the reference resolves to an
    // entity whose fields can be offered, so bind a saved host.
    $host = EntityTestWithBundle::create(['type' => 'main', 'name' => 'HOST']);
    $host->save();
    $this->assertTrue($component->setTargetPreviewEntity((string) $host->id()));
    $form = $this->buildPropForm($component, $this->editOpState());

    $section = $form['providers__aggregate'];
    $this->assertArrayHasKey('edit', $section, 'The edit pane replaces the list.');
    $this->assertArrayNotHasKey('list', $section);

    $settings = $section['edit']['settings'];
    $this->assertSame('table', $settings['shape_fields']['#type']);
    $this->assertSame(
      ['providers__aggregate', 'edit', 'settings', 'shape_fields', 'box'],
      $settings['shape_fields']['box']['content']['#parents'],
      'The cell level never reaches the value tree.'
    );
    $this->assertSame('invisible', $settings['shape_fields']['box']['content']['field']['#title_display'], 'The row names the child; the control label is for screen readers.');

    $this->assertSame(
      ['providers__aggregate', 'edit', 'settings', 'shape_published'],
      $settings['advanced']['shape_published']['#parents'],
      'Published-only stays at the provider root despite living behind Advanced.'
    );
    $this->assertSame(
      ['providers__aggregate', 'edit', 'settings', 'shape_fields', '_copy', 'source'],
      $settings['advanced']['_copy']['source']['#parents'],
      'Copy-mapping keeps the parents its submit handlers slice.'
    );
  }

  /**
   * A limited-validation trigger (Edit) transitions op without committing.
   */
  public function testEditTriggerDoesNotWipeStagedSettings(): void {
    $component = $this->buildComponent();
    $formObject = NULL;
    $formState = new FormState();
    $form = $this->buildPropForm($component, $formState, $formObject);
    $before = $component->getSetting('props')['_aggregate']['plugins'];

    $formState->setTriggeringElement($form['providers__aggregate']['list']['entity_reference']['ops']['edit']);
    $formObject->validateForm($form, $formState);

    $this->assertSame('edit', $formState->get('op'));
    $this->assertSame('entity_reference', $formState->get('op_plugin'));
    $this->assertSame($before, $component->getSetting('props')['_aggregate']['plugins'], 'An empty limited submission commits nothing.');
  }

  /**
   * Update commits the open pane's settings and returns to the list.
   *
   * Red-proof for the #limit_validation_errors default: a button carries
   * FALSE when unlimited, so a presence check classifies Update as limited
   * and this commit silently never happens.
   */
  public function testUpdateTriggerCommitsTheOpenPane(): void {
    $component = $this->buildComponent();
    $formObject = NULL;
    $formState = $this->editOpState();
    $form = $this->buildPropForm($component, $formState, $formObject);

    $formState->setValues([
      'active' => 1,
      'providers__aggregate' => [
        'edit' => [
          'settings' => [
            'entity' => 'field_ref:entity',
            'shape_fields' => $this->shapeFields(),
            'shape_published' => 1,
            // The one edited value.
            'processing_mode' => 'continue',
          ],
        ],
      ],
    ]);
    $formState->setTriggeringElement($form['providers__aggregate']['edit']['actions']['update']);
    $formObject->validateForm($form, $formState);

    $this->assertNull($formState->get('op'), 'Update returns to the list state.');
    $staged = $component->getSetting('props')['_aggregate']['plugins']['_aggregate']['entity_reference']['settings'];
    $this->assertSame('continue', $staged['processing_mode'], 'The edited value is staged on the unsaved entity.');
    $this->assertSame('field_ref:entity', $staged['entity'], 'Untouched values survive the commit.');
  }

  /**
   * Remove deactivates a provider; the staged settings drop it.
   */
  public function testRemoveTriggerDeactivates(): void {
    $component = $this->buildComponent();
    $formObject = NULL;
    $formState = new FormState();
    $form = $this->buildPropForm($component, $formState, $formObject);

    $formState->setTriggeringElement($form['providers__aggregate']['list']['entity_query']['ops']['remove']);
    $formObject->validateForm($form, $formState);

    $staged = $component->getSetting('props')['_aggregate']['plugins']['_aggregate'];
    $this->assertArrayNotHasKey('entity_query', $staged, 'The removed provider leaves the staged chain.');
    $this->assertArrayHasKey('entity_reference', $staged, 'The other provider is untouched.');
  }

  /**
   * The Default plugin summarizes scalar and nested array defaults.
   *
   * Regression pin: the summary flattens the stored default with
   * array_walk_recursive(), which takes its subject by reference — passing an
   * inline expression was a runtime fatal that took down every page listing
   * an active Default instance (the component manage screen included).
   */
  public function testDefaultValueSettingsSummary(): void {
    $component = $this->buildComponent();
    $shape = $component->getPropShape('_aggregate');
    $instance = $shape->getValueCollection()->getInstances()['default'];

    $instance->setConfiguration(['default' => ['title' => 'Component Title', 'size' => 'md']]);
    $summary = $instance->settingsSummary();
    $this->assertStringContainsString('Component Title, md', (string) reset($summary));

    $instance->setConfiguration(['default' => 'Plain scalar']);
    $summary = $instance->settingsSummary();
    $this->assertStringContainsString('Plain scalar', (string) reset($summary));

    $instance->setConfiguration(['default' => NULL]);
    $this->assertSame([], $instance->settingsSummary(), 'No default, no summary.');
  }

  /**
   * Re-adding a removed provider restores its last known settings.
   *
   * Serialization drops a deactivated instance's settings from the staged
   * entity, so without the restore the Add select would open an empty edit
   * pane over what the site builder had configured.
   */
  public function testReAddRestoresRemovedSettings(): void {
    $component = $this->buildComponent();
    $formObject = NULL;
    $formState = new FormState();
    $form = $this->buildPropForm($component, $formState, $formObject);

    // Remove entity_query, then pick it again in the Add select.
    $formState->setTriggeringElement($form['providers__aggregate']['list']['entity_query']['ops']['remove']);
    $formObject->validateForm($form, $formState);
    $this->assertArrayNotHasKey('entity_query', $component->getSetting('props')['_aggregate']['plugins']['_aggregate']);

    $formState->setUserInput(['providers__aggregate' => ['add' => 'entity_query']]);
    $formState->setValues(['active' => 1]);
    $addSelect = $form['providers__aggregate']['add'];
    $addSelect['#limit_validation_errors'] = FALSE;
    $formState->setTriggeringElement($addSelect);
    $formObject->validateForm($form, $formState);

    $this->assertSame('edit', $formState->get('op'));
    $staged = $component->getSetting('props')['_aggregate']['plugins']['_aggregate']['entity_query']['settings'];
    $this->assertSame('id', $staged['sort_field'], 'The removed provider comes back with its settings.');
    $this->assertSame($this->shapeFields(), $staged['shape_fields']);
  }

}
