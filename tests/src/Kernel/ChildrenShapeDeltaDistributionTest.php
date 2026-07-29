<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Guards per-delta value distribution into array child shapes.
 *
 * The bug this covers shipped silently and cost 130 images across ~40% of one
 * site's component trees:
 *
 * ComponentTreeHydrated::getValue() calls getPropShapesAll(NULL, TRUE) on every
 * live render, purely to find region props. That warms
 * ChildrenShapeBase::$childShapes[$delta] *before* the value-carrying call
 * arrives. getChildShapes() then takes its cache-hit branch, where
 * childHasOwnValueProvider() refuses to push the parent's value into any child
 * that owns a providers-group plugin — and an image child always owns one. The
 * authored media was dropped with no error anywhere.
 *
 * The fix narrows an iterable parent's delta-keyed override to $value[$delta]
 * before distributing it, so the priming call seeds each child correctly and
 * the later refusal is harmless.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ChildrenShapeBase::loadChildShapes()
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ChildrenShapeBase::getChildShapes()
 */
#[Group('neo_alchemist')]
class ChildrenShapeDeltaDistributionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * The authored value for each delta, keyed by delta.
   */
  private const AUTHORED = [
    0 => 'AUTHORED ZERO',
    1 => 'AUTHORED ONE',
    2 => 'AUTHORED TWO',
  ];

  /**
   * Builds the fixture component carrying an authored value per delta.
   *
   * Config scope plus preview values is the lightest way to give the `items`
   * array shape a delta-keyed override, which is the input loadChildShapes()
   * has to narrow. It avoids standing up a host entity and a
   * neo_component_tree field just to hand the shape a value.
   *
   * @param bool $useDefaults
   *   Whether each child's "default" option is left on. With it off (the
   *   default here) a child that fails to receive its authored value resolves
   *   to nothing rather than quietly falling back to the schema example, which
   *   makes the failure unambiguous.
   */
  private function buildComponent(bool $useDefaults = FALSE): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_array_provider')) {
      Component::create([
        'id' => 'na_array_provider',
        'label' => 'Array provider fixture',
        // Typed as a non-nullable string on the entity, so it must be supplied
        // explicitly rather than left to default from the SDC definition.
        'description' => 'Array provider fixture',
        'component' => 'neo_alchemist_test:na_array_provider',
        'status' => TRUE,
      ])->save();
    }
    // Always hand back a freshly loaded instance: shape state is memoised per
    // object, and tests that compare two resolutions must not share it.
    $storage->resetCache(['na_array_provider']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_array_provider');

    $value = [];
    $options = [];
    foreach (self::AUTHORED as $delta => $authored) {
      $value[$delta] = [
        'title' => ['value' => 'AUTHORED TITLE ' . $delta],
        'provided' => ['value' => $authored],
      ];
      $options['items~title~' . $delta] = ['default' => $useDefaults ? 1 : 0];
      $options['items~provided~' . $delta] = ['default' => $useDefaults ? 1 : 0];
    }

    $component->setPreview(TRUE);
    $component->setPreviewValues([
      'props' => [
        'items' => [
          'ref' => 'array',
          'value' => $value,
          'options' => $options,
        ],
      ],
    ]);

    return $component;
  }

  /**
   * Extracts the resolved `provided` child value for each delta.
   */
  private function resolvedProvidedValues(Component $component): array {
    $props = $component->getPropValues();
    $out = [];
    foreach ($props['items'] ?? [] as $delta => $item) {
      $out[$delta] = $item['provided'] ?? NULL;
    }
    return $out;
  }

  /**
   * Authored per-delta values survive the render-path priming call.
   *
   * This is the bug, reproduced exactly: prime the per-delta child shapes the
   * way ComponentTreeHydrated::getValue() does, then resolve.
   */
  public function testWarmedDeltaShapesKeepAuthoredChildValues(): void {
    $component = $this->buildComponent();

    // Exactly what happens on every live render before any value is resolved.
    $component->getPropShapesAll(NULL, TRUE);

    $resolved = $this->resolvedProvidedValues($component);

    foreach (self::AUTHORED as $delta => $authored) {
      $this->assertSame(
        $authored,
        $resolved[$delta] ?? NULL,
        sprintf('Delta %d kept its authored value after the shapes were primed.', $delta),
      );
      $this->assertNotSame('SCHEMA EXAMPLE', $resolved[$delta] ?? NULL);
    }
  }

  /**
   * Priming must not change what the component resolves to.
   *
   * Stated as an invariant rather than an expected value: whatever the correct
   * answer is, warming the shape cache first cannot be allowed to change it.
   * This is what makes the bug impossible to reintroduce in a new form — a
   * future caller that warms the cache differently still gets caught.
   */
  public function testWarmingDoesNotChangeResolvedValues(): void {
    $cold = $this->buildComponent();
    $coldValues = $this->resolvedProvidedValues($cold);

    $warm = $this->buildComponent();
    $warm->getPropShapesAll(NULL, TRUE);
    $warmValues = $this->resolvedProvidedValues($warm);

    $this->assertSame($coldValues, $warmValues, 'Priming the per-delta shapes changed the resolved values.');
  }

  /**
   * Each delta receives only its own value, never a sibling's.
   *
   * Directly covers the `$value = $value[$delta] ?? NULL` narrowing: before it,
   * the lookup was $value['<childName>'] against a delta-keyed array, which
   * always missed.
   */
  public function testDeltaKeyedValueIsNarrowedPerDelta(): void {
    $component = $this->buildComponent();
    $component->getPropShapesAll(NULL, TRUE);

    $resolved = $this->resolvedProvidedValues($component);

    $this->assertCount(count(self::AUTHORED), $resolved);
    $this->assertSame(array_values(self::AUTHORED), array_values($resolved), 'Values stayed aligned with their deltas.');
  }

  /**
   * No child of any delta resolves to nothing.
   *
   * The production symptom was an image prop rendering as nothing at all,
   * because MediaValue::provideDefaultValue() returns [] once the "default"
   * option is off. TestProviderValue is a pass-through instead, so with this
   * fixture a dropped value degrades to the schema example rather than to
   * empty — which is why the assertion here is emptiness, checked separately
   * from the value assertions above.
   *
   * The in-code guard is also exercised: zend.assertions=1 and
   * assert.exception=On in this environment, so the assert() in
   * getChildShapes() throws rather than warns if a child is ever refused a
   * value while holding none of its own.
   */
  public function testNoChildResolvesEmpty(): void {
    $this->assertSame('1', ini_get('zend.assertions'), 'Assertions must be live for the in-code guard to be meaningful.');

    $component = $this->buildComponent();
    $component->getPropShapesAll(NULL, TRUE);
    $props = $component->getPropValues();

    $this->assertNotEmpty($props['items'] ?? [], 'The array prop resolved at least one item.');
    foreach ($props['items'] as $delta => $item) {
      $this->assertArrayHasKey('provided', $item, sprintf('Delta %d kept its `provided` child.', $delta));
      $this->assertNotSame('', (string) $item['provided'], sprintf('Delta %d resolved a non-empty value.', $delta));
      $this->assertArrayHasKey('title', $item, sprintf('Delta %d kept its `title` child.', $delta));
    }
  }

  /**
   * Children left on "default" still fall back to the schema example.
   *
   * The counterpart to the tests above: the refusal in getChildShapes() is
   * legitimate when the child can still resolve a value another way. Without
   * this, a fix that simply forced every push through would look correct.
   */
  public function testDefaultOptionStillFallsBackToSchemaExample(): void {
    $component = $this->buildComponent(TRUE);
    $component->getPropShapesAll(NULL, TRUE);

    $resolved = $this->resolvedProvidedValues($component);

    foreach (array_keys(self::AUTHORED) as $delta) {
      $this->assertSame('SCHEMA EXAMPLE', $resolved[$delta] ?? NULL, sprintf('Delta %d used its default.', $delta));
    }
  }

}
