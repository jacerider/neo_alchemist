<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the branch table of ArrayShape::buildValue().
 *
 * The headline branch is the `continue 2` that drops an ENTIRE array item
 * when a required child resolves empty. "Empty" must follow the module's own
 * contract — isProvidedValueEmpty(), where 0, '0' and FALSE are values — not
 * PHP's empty(). Before the fix, a menu item titled "0" (or any array item
 * whose required number child held 0) silently vanished from the rendered
 * output.
 *
 * Red/green proof performed during development: with the pre-fix empty()
 * checks restored in ArrayShape::buildValue(),
 * testRequiredChildWithFalsyStringSurvives,
 * testRequiredNumberZeroSurvives and testSinglePropFalsyValueCollapses go
 * red with lost-content failures; the invariant methods stay green.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ArrayShape::buildValue()
 */
#[Group('neo_alchemist')]
class ArrayShapeBuildValueBranchTest extends KernelTestBase {

  use SdcPreviewStoreTestTrait;

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
   * Builds a fixture component with authored per-delta item values.
   *
   * @param array $items
   *   Authored items keyed by delta. Each child value is given in field-item
   *   format, e.g. ['title' => ['value' => '0']].
   * @param array $optionOverrides
   *   Per-child option overrides keyed 'items~<child>~<delta>'. Every child
   *   of every authored delta defaults to ['default' => 0] so a lost value
   *   resolves to nothing rather than quietly falling back to the schema
   *   example.
   * @param string $componentId
   *   The fixture component id.
   */
  private function buildComponent(array $items, array $optionOverrides = [], string $componentId = 'na_array_required'): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load($componentId)) {
      Component::create([
        'id' => $componentId,
        'label' => 'Array branch fixture',
        'description' => 'Array branch fixture',
        'component' => 'neo_alchemist_test:' . $componentId,
        'status' => TRUE,
      ])->save();
    }
    // Freshly loaded instance every time: shape state is memoised per object.
    $storage->resetCache([$componentId]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($componentId);

    $options = [];
    foreach ($items as $delta => $item) {
      foreach (array_keys($item) as $childName) {
        $options["items~$childName~$delta"] = ['default' => 0];
      }
    }
    $options = $optionOverrides + $options;

    $component->setPreview(TRUE);
    $this->setPreviewValues($component, [
      'props' => [
        'items' => [
          'ref' => 'array',
          'value' => $items,
          'options' => $options,
        ],
      ],
    ]);

    return $component;
  }

  /**
   * Branch D1: a required string child holding '0' keeps its item.
   */
  public function testRequiredChildWithFalsyStringSurvives(): void {
    $component = $this->buildComponent([
      0 => [
        'title' => ['value' => '0'],
        'count' => ['value' => 7],
      ],
      1 => [
        'title' => ['value' => 'AUTHORED ONE'],
        'count' => ['value' => 8],
      ],
    ]);

    $items = $component->getPropValues()['items'] ?? [];

    $this->assertCount(2, $items, 'Both items survived — a title of "0" is a value, not an empty required child.');
    $this->assertSame('0', $items[0]['title'] ?? NULL);
    $this->assertSame('AUTHORED ONE', $items[1]['title'] ?? NULL);
  }

  /**
   * Branch D1: a required number child holding 0 keeps its item.
   */
  public function testRequiredNumberZeroSurvives(): void {
    $component = $this->buildComponent([
      0 => [
        'title' => ['value' => 'AUTHORED ZERO'],
        'count' => ['value' => 0],
      ],
    ]);

    $items = $component->getPropValues()['items'] ?? [];

    $this->assertCount(1, $items, 'The item survived — a count of 0 is a value, not an empty required child.');
    $this->assertSame('AUTHORED ZERO', $items[0]['title'] ?? NULL);
    $this->assertSame(0.0, $items[0]['count'] ?? NULL, 'The number child resolved to 0.');
  }

  /**
   * An optional boolean child holding FALSE stays in the item.
   */
  public function testOptionalFalseBooleanKept(): void {
    $component = $this->buildComponent([
      0 => [
        'title' => ['value' => 'AUTHORED'],
        'count' => ['value' => 3],
        'flag' => ['value' => FALSE],
      ],
    ]);

    $items = $component->getPropValues()['items'] ?? [];

    $this->assertCount(1, $items);
    $this->assertArrayHasKey('flag', $items[0], 'The FALSE boolean child was kept.');
    $this->assertFalse($items[0]['flag']);
  }

  /**
   * Branch D1: a genuinely empty required child still drops the whole item.
   *
   * The counterpart that keeps the fix honest: '' IS empty per the contract,
   * and an item missing a required value would fail SDC validation, so the
   * drop is correct there.
   */
  public function testGenuinelyEmptyRequiredChildStillDropsItem(): void {
    $component = $this->buildComponent([
      0 => [
        'title' => ['value' => ''],
        'count' => ['value' => 4],
      ],
      1 => [
        'title' => ['value' => 'KEPT'],
        'count' => ['value' => 5],
      ],
    ]);

    $items = $component->getPropValues()['items'] ?? [];

    $this->assertCount(1, $items, 'The item with the empty required title was dropped.');
    $this->assertSame('KEPT', $items[0]['title'] ?? NULL, 'The surviving item was re-indexed for rendering.');
  }

  /**
   * Branch A: an optional child that was never authored is omitted.
   */
  public function testMissingOptionalChildUnset(): void {
    $component = $this->buildComponent([
      0 => [
        'title' => ['value' => 'AUTHORED'],
        'count' => ['value' => 1],
      ],
    ]);

    $items = $component->getPropValues()['items'] ?? [];

    $this->assertCount(1, $items);
    $this->assertArrayNotHasKey('note', $items[0], 'The never-authored optional child is absent.');
  }

  /**
   * Branch C: an explicitly-empty array for an optional child is omitted.
   */
  public function testExplicitlyEmptyArrayChildUnset(): void {
    $component = $this->buildComponent([
      0 => [
        'title' => ['value' => 'AUTHORED'],
        'count' => ['value' => 2],
        'note' => [],
      ],
    ]);

    $items = $component->getPropValues()['items'] ?? [];

    $this->assertCount(1, $items);
    $this->assertArrayNotHasKey('note', $items[0], 'An explicitly-empty array child means "no value".');
  }

  /**
   * Branch B: a child left on "default" resolves the schema example.
   */
  public function testDefaultOptionAdoptsSchemaExample(): void {
    $component = $this->buildComponent(
      [
        0 => [
          'title' => ['value' => 'AUTHORED'],
          'count' => ['value' => 6],
        ],
      ],
      ['items~title~0' => ['default' => 1]],
    );

    $items = $component->getPropValues()['items'] ?? [];

    $this->assertCount(1, $items);
    $this->assertSame('EXAMPLE TITLE 0', $items[0]['title'] ?? NULL, 'The default option still falls back to the schema example.');
  }

  /**
   * Branch B: an empty default no longer clobbers the raw authored slice.
   *
   * `note` has no schema example, so with its "default" option on the built
   * default resolves to nothing. The coherent outcome — pinned here — is the
   * explicitly-empty treatment: the optional child is omitted, and crucially
   * the rest of the item is untouched (before the fix the raw slice was
   * overwritten before the default was known to be usable).
   */
  public function testEmptyDefaultTreatedAsExplicitlyEmpty(): void {
    $component = $this->buildComponent(
      [
        0 => [
          'title' => ['value' => 'AUTHORED'],
          'count' => ['value' => 9],
          'note' => ['value' => 'AUTHORED NOTE'],
        ],
      ],
      ['items~note~0' => ['default' => 1]],
    );

    $items = $component->getPropValues()['items'] ?? [];

    $this->assertCount(1, $items, 'The item itself survived.');
    $this->assertSame('AUTHORED', $items[0]['title'] ?? NULL, 'The sibling children were untouched.');
    $this->assertArrayNotHasKey('note', $items[0], 'A child set to an empty default is omitted.');
  }

  /**
   * Branch E: single-prop items collapse for falsy values too.
   *
   * An array of bare strings must resolve to a homogeneous list of strings.
   * Before the fix, '0' skipped the wrapper collapse and the resolved list
   * mixed ['value' => '0'] with plain strings.
   */
  public function testSinglePropFalsyValueCollapses(): void {
    $component = $this->buildComponent(
      [
        0 => ['value' => ['value' => '0']],
        1 => ['value' => ['value' => 'AUTHORED']],
      ],
      [],
      'na_array_single',
    );

    $items = $component->getPropValues()['items'] ?? [];

    $this->assertCount(2, $items);
    $this->assertContainsOnly('string', $items, TRUE, 'Every item collapsed to a bare string.');
    $this->assertSame(['0', 'AUTHORED'], array_values($items));
  }

}
