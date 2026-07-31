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
 * hidden ("empty") and whether it starts on its default value. Those decisions
 * used to be written as `??` chains — `$config["{$f}_page"] ??
 * $config["{$f}_entity"] ?? FALSE` — which cannot express "any of these":
 * every key is seeded by defaultConfiguration(), so `??` always stopped at the
 * first one and the entity-label source never reached either branch. The
 * chains also read
 * differently for `title` than for its siblings, purely because `title_empty`
 * was the one key missing from the defaults.
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
   * Each case names one sub-prop setting and the hidden state it must produce.
   * All three sub-props keep `_edit` TRUE, because a non-editable sub-prop
   * takes the nestedOptions path instead and cannot show this branch at all.
   *
   * @return array
   *   Cases of [sub-prop, setting key, hidden?, why].
   */
  public static function sourceCases(): array {
    $cases = [];
    foreach (self::TEXT_KEYS as $key) {
      $cases["{$key}: no source => visible"] = [
        $key,
        NULL,
        FALSE,
        'Nothing was configured, so the sub-prop must not be hidden.',
      ];
      $cases["{$key}: page title source => hidden"] = [
        $key,
        'page',
        TRUE,
        'A sourced sub-prop starts hidden so the editor opts in to showing it.',
      ];
      $cases["{$key}: entity label source => hidden"] = [
        $key,
        'entity',
        TRUE,
        'The entity label is a source like any other; the ?? chain never reached it.',
      ];
      $cases["{$key}: entity field source => hidden"] = [
        $key,
        'field',
        TRUE,
        'An entity-field binding is a source too and must follow the same rule.',
      ];
      $cases["{$key}: hide ticked => hidden"] = [
        $key,
        'empty',
        TRUE,
        'The explicit Hide checkbox is the original reason this branch exists.',
      ];
    }
    return $cases;
  }

  /**
   * A configured source hides its sub-prop by default, whichever source it is.
   */
  #[DataProvider('sourceCases')]
  public function testSourceHidesSubProp(string $key, ?string $setting, bool $hidden, string $why): void {
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
  }

  /**
   * A source never hides a sibling sub-prop.
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

    $this->assertTrue($states['title']['empty'], 'The sourced sub-prop is hidden.');
    $this->assertFalse($states['supertitle']['empty'], 'Supertitle has no source of its own.');
    $this->assertFalse($states['subtitle']['empty'], 'Subtitle has no source of its own.');
  }

  /**
   * An explicit Default beats hidden: the two are either/or, and default wins.
   *
   * The provider form disables each of the two checkboxes while the other is
   * ticked, and onShapeInit() encodes the same exclusivity as an if/elseif. A
   * source must not smuggle the hidden flag past an explicit Default.
   */
  public function testExplicitDefaultBeatsSource(): void {
    $states = $this->optionStates([
      'supertitle_edit' => TRUE,
      'title_edit' => TRUE,
      'subtitle_edit' => TRUE,
      'title_entity' => TRUE,
      'title_default' => TRUE,
    ]);

    $this->assertFalse($states['title']['empty'], 'Default was ticked, so the elseif never runs.');
    $this->assertTrue($states['title']['default'], 'Default was ticked, so the sub-prop starts on its default.');
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
