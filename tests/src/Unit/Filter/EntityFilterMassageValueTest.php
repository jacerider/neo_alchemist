<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Filter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\Plugin\ComponentFilter\EntityFilter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins EntityFilter::massageFormValue()'s value round-trip.
 *
 * The single-value path previously wrapped the reduced value back into an
 * array and returned it from a ?string-typed method — a guaranteed
 * TypeError whenever a widget left in multi/tags mode submitted an array
 * for a single-value filter.
 *
 * Red/green proof performed during development: with the pre-fix
 * `$value = [reset($value)]; return $value;` restored,
 * testSingleValueArraySubmissionReturnsString and
 * testSingleAutocompleteRowReturnsTargetId go red with TypeErrors.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentFilter\EntityFilter::massageFormValue()
 */
#[Group('neo_alchemist')]
class EntityFilterMassageValueTest extends UnitTestCase {

  /**
   * Builds the filter plugin without its service/filter dependencies.
   *
   * EntityFilter is final and its constructor wants the filter wrapper plus
   * two services, none of which massageFormValue() touches — it reads only
   * $this->configuration. newInstanceWithoutConstructor() plus the public
   * setConfiguration() (which merges the defaults) is the lightest honest
   * harness.
   *
   * @param array $configuration
   *   Configuration overrides.
   */
  private function buildFilter(array $configuration = []): EntityFilter {
    $reflection = new \ReflectionClass(EntityFilter::class);
    /** @var \Drupal\neo_alchemist\Plugin\ComponentFilter\EntityFilter $plugin */
    $plugin = $reflection->newInstanceWithoutConstructor();
    $plugin->setConfiguration($configuration);
    return $plugin;
  }

  /**
   * Runs massageFormValue with stub form arguments.
   */
  private function massage(EntityFilter $plugin, array $value): ?string {
    return $plugin->massageFormValue($value, [], $this->createMock(FormStateInterface::class));
  }

  /**
   * A single-value filter reduces an array submission to a string.
   */
  public function testSingleValueArraySubmissionReturnsString(): void {
    $plugin = $this->buildFilter(['multiple' => FALSE]);

    $this->assertSame('5', $this->massage($plugin, ['value' => ['5']]));
  }

  /**
   * A single-value autocomplete row reduces to its target id.
   */
  public function testSingleAutocompleteRowReturnsTargetId(): void {
    $plugin = $this->buildFilter(['multiple' => FALSE]);

    $this->assertSame('7', $this->massage($plugin, ['value' => [['target_id' => 7]]]));
  }

  /**
   * A scalar single value passes through as a string.
   */
  public function testSingleScalarPassesThrough(): void {
    $plugin = $this->buildFilter(['multiple' => FALSE]);

    $this->assertSame('9', $this->massage($plugin, ['value' => '9']));
  }

  /**
   * Empty submissions return NULL.
   */
  public function testEmptySubmissionsReturnNull(): void {
    $plugin = $this->buildFilter(['multiple' => FALSE]);

    $this->assertNull($this->massage($plugin, ['value' => []]));
    $this->assertNull($this->massage($plugin, ['value' => '']));
    $this->assertNull($this->massage($plugin, []));
  }

  /**
   * Multi-value autocomplete joins target ids with the operator.
   */
  public function testMultipleAutocompleteJoinsTargetIds(): void {
    $plugin = $this->buildFilter([
      'multiple' => TRUE,
      'field_type' => 'autocomplete',
      'multiple_operator' => '+',
    ]);

    $this->assertSame('1+2', $this->massage($plugin, [
      'value' => [['target_id' => 1], ['target_id' => 2]],
    ]));
  }

}
