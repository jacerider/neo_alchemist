<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Falsy-but-real values survive the warm getChildShapes() path.
 *
 * The delta-distribution suite proved warming the per-delta child shapes
 * cannot change resolved values — for truthy values. The cache-hit branch of
 * ChildrenShapeBase::getChildShapes() additionally skipped pushing any child
 * slice that was empty() — exactly the shape of the original 130-image bug,
 * one type-domain over.
 *
 * Honest scope note: in the usual field-item format a falsy scalar arrives
 * wrapped (['value' => '0']), which empty() already passed, so this class
 * was green even before the predicate was aligned with
 * isProvidedValueEmpty() — it PINS warm/cold parity for falsy values rather
 * than proving a past failure. The same pattern in
 * StructuredObjectShapeBase::getChildShapes() (flat slices) WAS demonstrably
 * broken; ObjectShapeFalsyValueTest carries that red/green proof.
 *
 * The fixture is na_array_required — deliberately provider-free children, so
 * a falsy value reaching the provider-refusal guard is never in play and the
 * push itself is what is being tested.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\ChildrenShapeBase::getChildShapes()
 * @see \Drupal\Tests\neo_alchemist\Kernel\ChildrenShapeDeltaDistributionTest
 */
#[Group('neo_alchemist')]
class ChildrenShapeFalsyValueDistributionTest extends KernelTestBase {

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
   * The authored falsy title per delta.
   */
  private const AUTHORED = [
    0 => '0',
    1 => '0',
  ];

  /**
   * Builds the fixture component carrying falsy authored values per delta.
   */
  private function buildComponent(): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_array_required')) {
      Component::create([
        'id' => 'na_array_required',
        'label' => 'Array required fixture',
        'description' => 'Array required fixture',
        'component' => 'neo_alchemist_test:na_array_required',
        'status' => TRUE,
      ])->save();
    }
    $storage->resetCache(['na_array_required']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_array_required');

    $value = [];
    $options = [];
    foreach (self::AUTHORED as $delta => $authored) {
      $value[$delta] = [
        'title' => ['value' => $authored],
        'count' => ['value' => 0],
        'flag' => ['value' => FALSE],
      ];
      foreach (['title', 'count', 'flag'] as $childName) {
        $options["items~$childName~$delta"] = ['default' => 0];
      }
    }

    $component->setPreview(TRUE);
    $this->setPreviewValues($component, [
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
   * Extracts the resolved items.
   */
  private function resolvedItems(Component $component): array {
    return $component->getPropValues()['items'] ?? [];
  }

  /**
   * Falsy authored values survive the render-path priming call.
   */
  public function testFalsyAuthoredValuesSurviveWarmPath(): void {
    $this->assertSame('1', ini_get('zend.assertions'), 'Assertions must be live for the in-code guard to be meaningful.');

    $component = $this->buildComponent();

    // Exactly what happens on every live render before any value is resolved.
    $component->getPropShapesAll(NULL, TRUE);

    $items = $this->resolvedItems($component);

    $this->assertCount(count(self::AUTHORED), $items, 'No item was dropped.');
    foreach (self::AUTHORED as $delta => $authored) {
      $this->assertSame($authored, $items[$delta]['title'] ?? NULL, sprintf('Delta %d kept its authored "0" title after priming.', $delta));
      $this->assertSame(0.0, $items[$delta]['count'] ?? NULL, sprintf('Delta %d kept its authored 0 count after priming.', $delta));
      $this->assertFalse($items[$delta]['flag'] ?? NULL, sprintf('Delta %d kept its authored FALSE flag after priming.', $delta));
    }
  }

  /**
   * Priming must not change what falsy values resolve to.
   *
   * The invariant form: whatever the correct answer is, warming the shape
   * cache first cannot be allowed to change it.
   */
  public function testWarmingDoesNotChangeFalsyResolvedValues(): void {
    $cold = $this->buildComponent();
    $coldItems = $this->resolvedItems($cold);

    $warm = $this->buildComponent();
    $warm->getPropShapesAll(NULL, TRUE);
    $warmItems = $this->resolvedItems($warm);

    $this->assertSame($coldItems, $warmItems, 'Priming the per-delta shapes changed the resolved falsy values.');
  }

}
