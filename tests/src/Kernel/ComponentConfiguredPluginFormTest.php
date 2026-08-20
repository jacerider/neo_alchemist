<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginWrapperInterface;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The one form and one controller that serve access rules and filters.
 *
 * The reported defect is the first test here: the Filters tab offered plugins
 * the component cannot use, because the narrowing method that exists on the
 * access and slot managers was never added to the filter manager and the form
 * fell back to listing every definition. It is now on the manager base all
 * three share, so a fourth family cannot ship without it.
 *
 * The rest drives the shared form through form state and asserts what was
 * staged on the component — the same thing a site builder observes — for both
 * kinds, since the point of the consolidation is that neither can drift from
 * the other.
 *
 * @see \Drupal\neo_alchemist\Form\ComponentConfiguredPluginForm
 * @see \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginManagerBase
 */
#[Group('neo_alchemist')]
class ComponentConfiguredPluginFormTest extends KernelTestBase {

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
    $this->installConfig(['neo_alchemist']);
    $this->installEntitySchema('entity_test');
  }

  /**
   * Builds a component, optionally bound to a host entity type.
   */
  private function component(bool $entityBound = FALSE): Component {
    $values = [
      'label' => 'Configured plugin fixture',
      'description' => 'Configured plugin fixture',
      'component' => 'neo_alchemist_test:na_falsy_object',
      'status' => TRUE,
    ];
    if ($entityBound) {
      $values['target_entity_type'] = 'entity_test';
    }
    $component = Component::create($values);
    $component->save();
    return $component;
  }

  /**
   * Builds the shared form for one kind.
   *
   * @param \Drupal\neo_alchemist\Entity\Component $component
   *   The component.
   * @param string $kind
   *   The kind machine name: 'access' or 'filter'.
   * @param string|null $uuid
   *   An existing wrapper's uuid, or NULL to add a new one.
   * @param \Drupal\Core\Form\FormState|null $formState
   *   A form state to drive the build with.
   * @param object|null $formObject
   *   Returns the form object, for validateForm() calls.
   *
   * @return array
   *   The processed form.
   */
  private function buildForm(Component $component, string $kind, ?string $uuid = NULL, ?FormState $formState = NULL, mixed &$formObject = NULL): array {
    $kindObject = $this->container->get('neo_alchemist.configured_plugin_kinds')->get($kind);
    $wrapper = $uuid === NULL ? $kindObject->create($component) : $kindObject->load($component, $uuid);
    $this->assertInstanceOf(ConfiguredPluginWrapperInterface::class, $wrapper);

    $formObject = $this->container->get('entity_type.manager')->getFormObject('neo_component', $kind);
    $formObject->setEntity($component);
    $formState = $formState ?? new FormState();
    $formState->set($kind, $wrapper);
    return $this->container->get('form_builder')->buildForm($formObject, $formState);
  }

  /**
   * Stores a filter directly on a component and returns its uuid.
   */
  private function storeFilter(Component $component, array $overrides = []): string {
    // Overrides first: the union operator keeps the left-hand key.
    $filter = $this->container->get('neo_component.filter.factory')->get($component, $overrides + [
      'title' => 'Stored',
      'plugin_id' => 'string',
      'plugin_settings' => [],
      'value' => 'stored value',
    ]);
    return (string) $component->setFilter($filter)->uuid();
  }

  /**
   * The reported defect: the Filters tab offers only supported plugins.
   *
   * The fixture filter declines a component that is not registered against an
   * entity type, exactly as the shipped entity_field_value access rule does.
   * Before the manager base owned the narrowing, the filter form listed every
   * definition and picking this one produced a filter that does nothing.
   */
  public function testFilterFormOffersOnlyApplicablePlugins(): void {
    $form = $this->buildForm($this->component(), 'filter');
    $offered = array_keys($form['plugin_id']['#options']);

    $this->assertNotContains('na_entity_bound_filter', $offered, 'A filter the component cannot use is not offered.');
    $this->assertContains('string', $offered, 'Premise: the generic filters are still offered.');

    $bound = array_keys($this->buildForm($this->component(TRUE), 'filter')['plugin_id']['#options']);
    $this->assertContains('na_entity_bound_filter', $bound, 'The same filter is offered where it applies.');
  }

  /**
   * Narrowing changes what can be added, never what is already configured.
   *
   * "Existing stored configuration is not touched … such a filter becomes
   * visible only as an already-configured row." Dropping the stored plugin
   * from this select would leave a site builder on a required field with
   * nothing selected and no way to save the screen they opened.
   */
  public function testNarrowingDoesNotStrandConfiguredPlugin(): void {
    $component = $this->component();
    $uuid = $this->storeFilter($component, ['plugin_id' => 'na_entity_bound_filter']);

    $offered = array_keys($this->buildForm($component, 'filter', $uuid)['plugin_id']['#options']);
    $this->assertContains('na_entity_bound_filter', $offered, 'The configured plugin stays selectable on its own edit screen.');

    $addable = array_keys($this->buildForm($component, 'filter')['plugin_id']['#options']);
    $this->assertNotContains('na_entity_bound_filter', $addable, 'It is still not offered for a new filter.');
  }

  /**
   * The access tab narrows the same way, through the same method.
   */
  public function testAccessFormOffersOnlyApplicablePlugins(): void {
    $offered = array_keys($this->buildForm($this->component(), 'access')['plugin_id']['#options']);
    $this->assertNotContains('entity_field_value', $offered, 'A field check needs a host entity type.');
    // Not `role` or `permission`: their shared base uses neo_icon, which a
    // kernel test does not enable, so discovery never sees them here.
    $this->assertContains('protected', $offered, 'Premise: the generic rules are still offered.');

    $bound = array_keys($this->buildForm($this->component(TRUE), 'access')['plugin_id']['#options']);
    $this->assertContains('entity_field_value', $bound);
  }

  /**
   * Both kinds share the plugin select and its settings pane.
   */
  #[DataProvider('kindProvider')]
  public function testBothKindsShareThePluginPicker(string $kind): void {
    $form = $this->buildForm($this->component(), $kind);
    $this->assertSame('select', $form['plugin_id']['#type']);
    $this->assertTrue($form['plugin_id']['#required']);
    $this->assertNotEmpty($form['plugin_id']['#options']);
    $this->assertArrayNotHasKey('plugin_settings', $form, 'No plugin picked yet, so nothing to configure.');
  }

  /**
   * The two kinds, for tests that must hold for both.
   */
  public static function kindProvider(): array {
    return ['access' => ['access'], 'filter' => ['filter']];
  }

  /**
   * A filter carries its own five fields; an access rule carries none.
   *
   * They are supplied by the kind rather than by forking the form, so this is
   * what pins that the hook actually reaches the shared build.
   */
  public function testTheKindSuppliesItsOwnFields(): void {
    $component = $this->component();
    $filterForm = $this->buildForm($component, 'filter');
    foreach (['title', 'description', 'editable', 'required'] as $field) {
      $this->assertArrayHasKey($field, $filterForm, sprintf('The filter form carries %s.', $field));
    }
    $this->assertLessThan($filterForm['plugin_id']['#weight'], $filterForm['title']['#weight'], 'The title frames the plugin choice.');
    $this->assertGreaterThan($filterForm['plugin_id']['#weight'], $filterForm['required']['#weight'], 'The flags follow it.');

    $accessForm = $this->buildForm($component, 'access');
    foreach (['title', 'description', 'editable', 'required', 'value'] as $field) {
      $this->assertArrayNotHasKey($field, $accessForm, sprintf('An access rule has no %s.', $field));
    }
  }

  /**
   * Saving a filter stages the plugin, its settings and the filter's fields.
   *
   * Driven from an existing filter, because the Default Value widget is built
   * by the chosen plugin: picking one rebuilds the form over AJAX and only
   * then is there a value to submit.
   */
  public function testFilterSubmissionStagesEverything(): void {
    $component = $this->component();
    $uuid = $this->storeFilter($component);

    $formObject = NULL;
    $formState = new FormState();
    $form = $this->buildForm($component, 'filter', $uuid, $formState, $formObject);
    $this->assertArrayHasKey('value', $form, 'Premise: the chosen plugin built a default-value widget.');

    $formState->setValues([
      'title' => 'Section',
      'description' => 'Which section to show.',
      'plugin_id' => 'string',
      'editable' => 1,
      'required' => 0,
      'value' => ['_empty' => 0, 'value' => ['value' => 'news']],
    ]);
    $formObject->validateForm($form, $formState);

    $stored = $component->getSetting('filters', [])[$uuid];
    $this->assertSame('string', $stored['plugin_id']);
    $this->assertSame('Section', $stored['title']);
    $this->assertSame('Which section to show.', $stored['description']);
    $this->assertTrue((bool) $stored['editable']);
    $this->assertSame('news', $stored['value'], 'The plugin massaged the default value.');
  }

  /**
   * Saving an access rule stages the plugin and its settings.
   */
  public function testAccessSubmissionStagesEverything(): void {
    $component = $this->component();
    $formObject = NULL;
    $formState = new FormState();
    $form = $this->buildForm($component, 'access', NULL, $formState, $formObject);
    $formState->setValues(['plugin_id' => 'na_cache_tag_access']);
    $formObject->validateForm($form, $formState);

    $access = $component->getSetting('access', []);
    $this->assertCount(1, $access);
    $stored = reset($access);
    $this->assertSame('na_cache_tag_access', $stored['plugin_id']);
  }

  /**
   * Delete submits nothing, and must therefore commit nothing.
   *
   * Its #limit_validation_errors is [], so every value the commit path reads
   * is absent: running it clears the plugin id, which clears the settings with
   * it. The old forms did exactly that and got away with it only because
   * ::delete() threw the emptied wrapper away immediately afterwards.
   */
  #[DataProvider('kindProvider')]
  public function testDeleteDoesNotCommitAnEmptyValueSet(string $kind): void {
    $component = $this->component();
    if ($kind === 'filter') {
      $uuid = $this->storeFilter($component);
    }
    else {
      $access = $this->container->get('neo_component.access.factory')->get($component, [
        'plugin_id' => 'na_cache_tag_access',
        'plugin_settings' => ['tag' => 'kept'],
      ]);
      $uuid = (string) $component->setAccess($access)->uuid();
    }
    $before = $component->getSetting($kind === 'filter' ? 'filters' : 'access');

    $formObject = NULL;
    $formState = new FormState();
    $form = $this->buildForm($component, $kind, $uuid, $formState, $formObject);
    $this->assertArrayHasKey('delete', $form['actions'], 'Premise: a stored wrapper offers Delete.');
    $this->assertSame([], $form['actions']['delete']['#limit_validation_errors'], 'Premise: Delete limits validation to nothing.');
    // What a limited submission actually looks like by the time validateForm()
    // runs: the form builder has pruned the values to the limited set, which
    // here is none of them.
    $formState->setValues([]);
    $formState->setTriggeringElement($form['actions']['delete']);
    $formObject->validateForm($form, $formState);

    $this->assertSame($before, $component->getSetting($kind === 'filter' ? 'filters' : 'access'), 'A limited submission commits nothing.');
  }

  /**
   * An unknown uuid is not a 404 dressed as an empty add form.
   */
  public function testEditingSomethingThatIsNotThereIsDenied(): void {
    $component = $this->component();
    $controller = $this->container->get('class_resolver')
      ->getInstanceFromDefinition('Drupal\neo_alchemist\Controller\ComponentConfiguredPluginController');

    $this->expectException(AccessDeniedHttpException::class);
    $controller($component, 'filter', 'no-such-uuid');
  }

  /**
   * A route naming a kind that does not exist fails loudly.
   */
  public function testUnknownKindThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->container->get('neo_alchemist.configured_plugin_kinds')->get('slot');
  }

}
