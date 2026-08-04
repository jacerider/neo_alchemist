<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * What each Heading sub-prop setting does to that sub-prop's nested options.
 *
 * HeadingValue::onShapeInit() decides, per sub-prop, whether the child starts
 * hidden ("empty") and whether it starts on its default value. The rule is that
 * a source — the page title, the entity label or a bound entity field — starts
 * the sub-prop on its default, because that default is what the source
 * produced, and that only the site builder's own Hide checkbox starts it
 * hidden. Getting those two backwards is not a cosmetic difference: the empty
 * option short-circuits ComponentShapePluginBase::getValue() before the default
 * one is ever read, so a sourced-and-hidden sub-prop resolves its value and
 * then throws it away.
 *
 * These decisions used to be written as `??` chains — `$config["{$f}_page"] ??
 * $config["{$f}_entity"] ?? FALSE` — which cannot express "any of these":
 * every key is seeded by defaultConfiguration(), so `??` always stopped at the
 * first one and the entity-label source never reached either branch. The
 * chains also read
 * differently for `title` than for its siblings, purely because `title_empty`
 * was the one key missing from the defaults — which is how `title` alone ended
 * up hidden whenever it was sourced, and how that accident came to be read as
 * the intended rule.
 *
 * None of that was visible from the outside, for a reason this suite also pins
 * (::testEditFalseShadowsTheHiddenDefault): when a sub-prop is not editable,
 * onShapeInit() writes nestedOptions for it, and getNestedOptions() unions
 * `nestedOptions + defaultNestedOptions` by TOP-LEVEL key — so one saved option
 * discards the whole default entry, hidden flag included. Every heading in the
 * site that prompted this change had `_edit: false`, so the branch under test
 * was inert and the bug could not be observed by editing a component.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\HeadingValue::onShapeInit()
 */
#[Group('neo_alchemist')]
class HeadingValueSubPropOptionsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    // The heading's `size` sub-prop is a StyleShape backed by `list_string`,
    // which the options module supplies. Without it the whole prop fails to
    // build, long before any of these settings are read.
    'options',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * The sub-props whose value the provider can source.
   */
  private const TEXT_KEYS = ['supertitle', 'title', 'subtitle'];

  /**
   * Builds the heading fixture with the given Heading provider settings.
   *
   * The component targets `user` so that the entity-label and entity-field
   * sources have a real entity type to resolve against without pulling `node`
   * (and a node type) into the module list.
   *
   * @param array $settings
   *   Heading provider settings, merged over the plugin's own defaults.
   *
   * @return \Drupal\neo_alchemist\Entity\Component
   *   The saved component, freshly loaded.
   */
  private function buildComponent(array $settings): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_heading')) {
      Component::create([
        'id' => 'na_heading',
        'label' => 'Heading fixture',
        'description' => 'Heading fixture',
        'component' => 'neo_alchemist_test:na_heading',
        'target_entity_type' => 'user',
        'status' => TRUE,
      ])->save();
    }
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_heading')
      ->set('settings.props.heading.prop', 'heading')
      ->set('settings.props.heading.ref', 'heading')
      ->set('settings.props.heading.active', TRUE)
      ->set('settings.props.heading.editable', TRUE)
      ->set('settings.props.heading.plugins.heading.heading', [
        'id' => 'heading',
        'settings' => $settings,
      ])
      ->save();
    $storage->resetCache(['na_heading']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_heading');
    return $component;
  }

  /**
   * Reads the resolved hidden/default state of every text sub-prop.
   *
   * @param array $settings
   *   Heading provider settings.
   *
   * @return array
   *   A sub-prop name => ['empty' => bool, 'default' => bool] map.
   */
  private function optionStates(array $settings): array {
    $shape = $this->buildComponent($settings)->getPropShapes()['heading'];
    $this->assertInstanceOf(ComponentShapeChildrenPluginInterface::class, $shape);
    $states = [];
    foreach ($shape->getChildShapes() as $name => $child) {
      if (!in_array($name, self::TEXT_KEYS, TRUE)) {
        continue;
      }
      $states[$name] = [
        'empty' => $child->getOptionEmpty()->isEnabled(),
        'default' => $child->getOptionDefault()->isEnabled(),
      ];
    }
    return $states;
  }

  /**
   * Every way a sub-prop can be told where its value comes from.
   *
   * Each case names one sub-prop setting and the hidden/default state it must
   * produce. All three sub-props keep `_edit` TRUE, because a non-editable
   * sub-prop takes the nestedOptions path instead and cannot show this branch
   * at all.
   *
   * @return array
   *   Cases of [sub-prop, setting key, hidden?, on default?, why].
   */
  public static function sourceCases(): array {
    $cases = [];
    foreach (self::TEXT_KEYS as $key) {
      $cases["{$key}: no source => visible, not defaulted"] = [
        $key,
        NULL,
        FALSE,
        FALSE,
        'Nothing was configured, so the sub-prop is left alone.',
      ];
      $cases["{$key}: page title source => visible, defaulted"] = [
        $key,
        'page',
        FALSE,
        TRUE,
        'The page title IS the default value; hiding it would make the setting inert.',
      ];
      $cases["{$key}: entity label source => visible, defaulted"] = [
        $key,
        'entity',
        FALSE,
        TRUE,
        'The entity label is a source like any other and renders the same way.',
      ];
      $cases["{$key}: entity field source => visible, defaulted"] = [
        $key,
        'field',
        FALSE,
        TRUE,
        'An entity-field binding is a source too and must follow the same rule.',
      ];
      $cases["{$key}: hide ticked => hidden"] = [
        $key,
        'empty',
        TRUE,
        FALSE,
        'The explicit Hide checkbox is the only thing that starts a sub-prop hidden.',
      ];
    }
    return $cases;
  }

  /**
   * A configured source starts its sub-prop on the value that source produced.
   *
   * The rule this pins is the inverse of what the code used to do: a source
   * used to ALSO start the sub-prop hidden, and the empty option beats the
   * default one in ComponentShapePluginBase::getValue(), so ticking "Use page
   * title as value" resolved the page title and then discarded it — an empty
   * heading in the component preview and on every instance created afterwards.
   * Hiding is the site builder's own switch (`{$key}_empty`) and nothing else.
   */
  #[DataProvider('sourceCases')]
  public function testSourceDefaultsSubProp(string $key, ?string $setting, bool $hidden, bool $default, string $why): void {
    $settings = [];
    foreach (self::TEXT_KEYS as $textKey) {
      $settings["{$textKey}_edit"] = TRUE;
    }
    if ($setting !== NULL) {
      // The field source is a matcher key; any non-empty string binds it, and
      // the default "fall back" mode means it is never resolved here.
      $settings["{$key}_{$setting}"] = $setting === 'field' ? 'name' : TRUE;
    }

    $states = $this->optionStates($settings);

    $this->assertSame($hidden, $states[$key]['empty'], $why);
    $this->assertSame($default, $states[$key]['default'], $why);
  }

  /**
   * A source never touches a sibling sub-prop.
   *
   * The settings are a flat `{sub-prop}_{setting}` namespace, so a mistake in
   * the key interpolation would leak one sub-prop's source onto another. This
   * is cheap insurance against that.
   */
  public function testSourceDoesNotLeakToSiblings(): void {
    $states = $this->optionStates([
      'supertitle_edit' => TRUE,
      'title_edit' => TRUE,
      'subtitle_edit' => TRUE,
      'title_entity' => TRUE,
    ]);

    $this->assertTrue($states['title']['default'], 'The sourced sub-prop starts on its default.');
    $this->assertFalse($states['supertitle']['default'], 'Supertitle has no source of its own.');
    $this->assertFalse($states['subtitle']['default'], 'Subtitle has no source of its own.');
    foreach (self::TEXT_KEYS as $key) {
      $this->assertFalse($states[$key]['empty'], "The {$key} sub-prop was hidden by a source.");
    }
  }

  /**
   * An explicit Default and a source agree rather than compete.
   *
   * The provider form disables each of the Default/Hide checkboxes while the
   * other is ticked, so they are either/or in the UI. Ticking Default on a
   * sub-prop that already has a source is a no-op: both roads lead to the
   * sub-prop starting on the value the source produced.
   */
  public function testExplicitDefaultBeatsSource(): void {
    $states = $this->optionStates([
      'supertitle_edit' => TRUE,
      'title_edit' => TRUE,
      'subtitle_edit' => TRUE,
      'title_entity' => TRUE,
      'title_default' => TRUE,
    ]);

    $this->assertFalse($states['title']['empty'], 'Neither Default nor a source hides a sub-prop.');
    $this->assertTrue($states['title']['default'], 'Default was ticked, so the sub-prop starts on its default.');
  }

  /**
   * The sourced value survives all the way to the resolved prop value.
   *
   * The option assertions above describe the mechanism; this one describes the
   * symptom that sent anyone looking at it. A heading whose title is sourced
   * from the page title must resolve to that title — under the old
   * source-implies-hidden rule the sub-prop was silently dropped from the
   * object here, and the component rendered an empty heading.
   */
  public function testSourcedTitleReachesTheResolvedValue(): void {
    $component = $this->buildComponent([
      'supertitle_edit' => TRUE,
      'title_edit' => TRUE,
      'subtitle_edit' => TRUE,
      'title_page' => TRUE,
    ]);
    $component->setPreview(TRUE);

    $value = $component->getPropShapes()['heading']->getValue();

    $this->assertArrayHasKey('title', $value, 'The sourced title was dropped from the heading value.');
    // Preview mode has no page to take a title from, so the provider stands in
    // a placeholder — the exact string is HeadingValue's business, but that
    // SOMETHING arrived is this test's.
    $this->assertNotSame('', (string) $value['title']);
  }

  /**
   * A non-editable sub-prop discards the hidden default, and always has.
   *
   * This is not the desired behaviour so much as the reason the `??` bug went
   * unnoticed: the !edit branch writes nestedOptions for the sub-prop, and
   * getNestedOptions() unions `nestedOptions + defaultNestedOptions` by
   * top-level key, so the whole default entry — hidden flag included — is
   * dropped. Pinned so that changing the union to a deep merge (a separate,
   * module-wide change) cannot happen silently: this test is what will fail.
   */
  public function testEditFalseShadowsTheHiddenDefault(): void {
    $states = $this->optionStates([
      'supertitle_edit' => TRUE,
      'title_edit' => FALSE,
      'subtitle_edit' => TRUE,
      'title_entity' => TRUE,
    ]);

    $this->assertFalse(
      $states['title']['empty'],
      'nestedOptions written by the !edit branch shadow the whole defaultNestedOptions entry.',
    );
  }

  /**
   * Every text sub-prop has the same set of settings keys.
   *
   * `title_empty` was absent from defaultConfiguration() while its two
   * siblings had it, which silently changed what the hidden branch read for
   * `title` alone. The asymmetry is the bug; this pins the symmetry.
   */
  public function testTextSubPropsShareTheSameSettings(): void {
    $config = $this->buildComponent([])
      ->getPropShapes()['heading']
      ->getValueCollection()
      ->get('heading')
      ->getConfiguration();

    $suffixes = [];
    foreach (self::TEXT_KEYS as $key) {
      $keys = array_filter(array_keys($config), static fn ($name) => str_starts_with($name, $key . '_'));
      $suffixes[$key] = array_values(array_map(static fn ($name) => substr($name, strlen($key) + 1), $keys));
      sort($suffixes[$key]);
    }

    // Compared against each other rather than a literal list: which keys a
    // config round-trip preserves is not this test's business, but every text
    // sub-prop answering to the same set of them is.
    $reference = $suffixes[self::TEXT_KEYS[0]];
    foreach ($suffixes as $key => $actual) {
      $this->assertSame($reference, $actual, "The {$key} sub-prop has a different set of settings from its siblings.");
    }
    $this->assertContains('empty', $reference, 'Every text sub-prop can be hidden; title used to be the exception.');
  }

}
