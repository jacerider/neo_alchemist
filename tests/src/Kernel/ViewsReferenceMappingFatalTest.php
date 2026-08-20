<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Plugin\ComponentValue\ViewsValue;
use Drupal\user\Entity\User;
use Drupal\views\ViewExecutable;
use PHPUnit\Framework\Attributes\Group;

/**
 * Regression: the `views` provider + a reference pseudo-field must not fatal.
 *
 * A site builder attaches the `views` provider to an iterable prop and maps one
 * of its child shapes through the `_reference~` pseudo-field — an option the
 * configuration form offers for iterable children. At render time the mapping
 * follows that reference, which needs the reference matcher.
 *
 * When this test was written the mapping lived in a trait the producers mixed
 * in, and its three collaborators were assigned by convention across seven
 * hand-written constructors. Six of the seven assigned the reference matcher;
 * ViewsValue assigned the field matcher and stopped, so the reference matcher
 * was never initialised and the page died with "Typed property must not be
 * accessed before initialization" — a white screen, not a degraded render.
 * The mapping is now the container-constructed ChildrenMatchMapper, which is
 * what makes the omission impossible rather than merely fixed; this test
 * stays as the check that the inversion kept the fix.
 *
 * The offer/fetch split is what makes it a white screen: the code that OFFERS
 * the option goes through the lazy getMatcherReference() accessor (so the
 * option appears), while the code that FETCHES reads the raw typed property (so
 * the fetch fatals). This pins the fetch path against the container wiring.
 *
 * The `views` provider only reaches its mapping once it has entities in hand,
 * which normally means executing a configured view. Executing a real view in a
 * Kernel test would drag in view config, a base-table entity resolution and a
 * pager for no extra signal about THIS defect, which is purely that the
 * container failed to supply a collaborator. So the executed view is injected
 * through the provider's own memoisation fields (the exact state a real
 * getView() would have produced) and the REAL, container-built provider is
 * driven through its REAL provideDefaultValue(). The wiring under test is
 * untouched: the plugin is constructed through ViewsValue::create(), so a
 * collaborator the container fails to supply is still absent here.
 *
 * Proven red by mutation: removing the $this->matcherReference assignment from
 * ViewsValue's constructor makes testViewsProviderReferenceMappingRenders()
 * throw the initialization Error instead of resolving a value.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsValue
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper::fetchReference()
 */
#[Group('neo_alchemist')]
class ViewsReferenceMappingFatalTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
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
  }

  /**
   * The views provider resolves a reference-mapped child instead of fataling.
   */
  public function testViewsProviderReferenceMappingRenders(): void {
    $entity = User::create(['name' => 'Row entity']);
    $entity->save();

    $shape = $this->buildAggregateShape();

    // A child of the aggregate object — the iterable `items` array — mapped
    // through the reference pseudo-field, with its own non-empty mapping so the
    // reference fetch actually runs (an empty nested mapping returns before it
    // touches the reference matcher). `field_ref` need not exist on the row
    // entity: the fatal was the property access, which precedes any field
    // lookup, so a graceful "no such reference" resolution is the proof the
    // wiring is fixed.
    $settings = [
      'view_id' => 'na_dummy',
      'view_display_id' => 'default',
      'shape_published' => FALSE,
      'shape_fields' => [
        'items' => [
          'field' => '_reference~field_ref',
          'shape_fields' => [
            'title' => ['field' => '_raw:string', 'string' => 'RAW TITLE'],
          ],
        ],
      ],
    ];

    $provider = $this->buildViewsProvider($shape, $settings);
    $this->injectExecutedView($provider, $entity);

    // Before the fix this throws an Error: the typed $matcherReference property
    // on ViewsValue must not be accessed before initialization.
    $value = $provider->provideDefaultValue(['SCHEMA SEED']);

    $this->assertIsArray(
      $value,
      'The views provider resolved its value through the reference pseudo-field instead of fataling on an uninitialised reference matcher.',
    );
    // It ran its own mapping over the view row rather than handing back the
    // untouched seed — the reference fetch path was genuinely exercised.
    $this->assertNotSame(['SCHEMA SEED'], $value);
  }

  /**
   * Builds a saved aggregate component and returns its `_aggregate` shape.
   *
   * Aggregation puts an object shape above the array prop, so `items` is
   * reached as a child and is iterable — which is what makes the reference
   * pseudo-field an offered option for it.
   *
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
   *   The aggregate object shape.
   */
  private function buildAggregateShape(): ComponentShapePluginInterface {
    $component = Component::create([
      'label' => 'Views reference fixture',
      'description' => 'Views reference fixture',
      'component' => 'neo_alchemist_test:na_array_required',
      'status' => TRUE,
      'aggregate' => TRUE,
      'target_entity_type' => 'user',
    ]);
    $component->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $storage->resetCache([$component->id()]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($component->id());
    return $component->getPropShape('_aggregate');
  }

  /**
   * Constructs the `views` provider exactly as the container would.
   *
   * ViewsValue::create() is the site of the defect: it wires the field matcher
   * but not the reference matcher, so building through it is what reproduces
   * the missing collaborator.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The children-match shape to bind the provider to.
   * @param array $settings
   *   The provider settings, including the reference mapping.
   *
   * @return \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsValue
   *   The constructed provider.
   */
  private function buildViewsProvider(ComponentShapePluginInterface $shape, array $settings): ViewsValue {
    $definition = $this->container->get('plugin.manager.neo_component_value')->getDefinition('views');
    return ViewsValue::create($this->container, [
      'shape' => $shape,
      'settings' => $settings,
    ], 'views', $definition);
  }

  /**
   * Hands the provider an already-executed view carrying one entity row.
   *
   * The provider memoises its executed view in `view`/`viewResolved`; seeding
   * them is the state a real execution leaves behind, and lets the real
   * provideDefaultValue() run its mapping without a view query. ViewsValue is
   * final, so this is done by reflection rather than by a test subclass.
   */
  private function injectExecutedView(ViewsValue $provider, ContentEntityInterface $entity): void {
    $row = new \stdClass();
    $row->_entity = $entity;
    $row->index = 0;

    $view = $this->createMock(ViewExecutable::class);
    $view->result = [$row];
    $view->args = [];

    $reflection = new \ReflectionObject($provider);
    $viewProperty = $reflection->getProperty('view');
    $viewProperty->setAccessible(TRUE);
    $viewProperty->setValue($provider, $view);
    $resolvedProperty = $reflection->getProperty('viewResolved');
    $resolvedProperty->setAccessible(TRUE);
    $resolvedProperty->setValue($provider, TRUE);
  }

}
