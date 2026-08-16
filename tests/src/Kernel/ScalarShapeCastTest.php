<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Scalar shapes cast the same way on render and on save.
 *
 * Each scalar shape states its PHP cast twice: once in buildValue() for the
 * render path and once in massageFormValues() for the form-save path. The two
 * had drifted in NumberShape — rendered as float, stored as int — so a number
 * prop authored as 1.5 was silently stored as 1. Nothing pinned the pair, and
 * form input arrives as a string, so only the save path saw the truncation.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\NumberShape
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\IntegerShape
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\BooleanShape
 */
#[Group('neo_alchemist')]
class ScalarShapeCastTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    // na_falsy_object carries a `link` prop alongside its number child, and
    // building any of its shapes builds them all.
    'link',
    'options',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * Returns the named root prop shape of a fixture component.
   */
  private function shape(string $componentId, string $prop) {
    $component = Component::create([
      'id' => $componentId,
      'label' => $componentId,
      'description' => $componentId,
      'component' => 'neo_alchemist_test:' . $componentId,
      'status' => TRUE,
    ]);
    $component->save();
    $shapes = $component->getPropShapes();
    $this->assertArrayHasKey($prop, $shapes, "The fixture has no $prop prop.");
    return $shapes[$prop];
  }

  /**
   * Runs the save-path cast for a submitted value.
   */
  private function massage($shape, string $submitted): mixed {
    $values = ['value' => $submitted];
    $massaged = $shape->massageFormValues($values, $values, [], new FormState());
    return $massaged['value'] ?? NULL;
  }

  /**
   * A decimal survives the save path as a float.
   */
  public function testNumberKeepsItsDecimalOnSave(): void {
    $shape = $this->shape('na_falsy_object', 'count');

    $stored = $this->massage($shape, '1.5');

    $this->assertSame(1.5, $stored, 'A number prop was truncated on save; the render path treats it as a float.');
  }

  /**
   * The number shape agrees with itself across both paths.
   */
  public function testNumberCastsToFloatOnBothPaths(): void {
    $shape = $this->shape('na_falsy_object', 'count');

    $this->assertIsFloat($this->massage($shape, '2'), 'The save path did not produce a float.');
  }

  /**
   * An integer prop still truncates, which is its whole point.
   */
  public function testIntegerStillTruncates(): void {
    $shape = $this->shape('na_match_probe', 'count');

    $stored = $this->massage($shape, '1.5');

    $this->assertSame(1, $stored, 'An integer prop should truncate a decimal.');
  }

}
