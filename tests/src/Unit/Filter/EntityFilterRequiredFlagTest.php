<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Filter;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\Filter\ComponentFilterInterface;
use Drupal\neo_alchemist\Plugin\ComponentFilter\EntityFilter;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins which form EntityFilter marks a required filter required on.
 *
 * "Required" means two different things across the two forms this plugin
 * builds. On a placement's instance form (the "Context" accordion) a required
 * filter must be filled before the component can be saved. On the component's
 * own default-value form an *editable* required filter may be left empty,
 * because every placement supplies its own value there.
 *
 * ComponentFilterPluginBase encodes exactly that by branching on
 * $is_default_form. EntityFilter overrides buildForm() and used to ignore the
 * parameter: the autocomplete branch hardcoded the default-form half
 * (`isRequired() && !isEditable()`), so a required editable autocomplete filter
 * was never required on the instance form and a component could be placed with
 * it empty; the select/checkbox branch hardcoded the other half
 * (`isRequired()`), forcing a value on the default-value form that belongs to
 * the placements.
 *
 * Red/green proof performed during development: restoring either hardcoded
 * expression turns the matching rows of this provider red.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentFilter\EntityFilter::buildForm()
 * @see \Drupal\neo_alchemist\Filter\ComponentFilterPluginBase::buildForm()
 */
#[Group('neo_alchemist')]
class EntityFilterRequiredFlagTest extends UnitTestCase {

  /**
   * Builds the plugin without its container dependencies.
   *
   * EntityFilter is final and its constructor wants three collaborators the
   * required-flag logic never reads. The autocomplete branch only touches the
   * entity type manager when a stored value has to be loaded back, and this
   * test always leaves the value empty — the state a placement is added in,
   * and the only one where the missing required flag mattered.
   *
   * @param array $configuration
   *   Configuration overrides.
   * @param bool $required
   *   What the wrapping filter reports for isRequired().
   * @param bool $editable
   *   What the wrapping filter reports for isEditable().
   */
  private function buildFilter(array $configuration, bool $required, bool $editable): EntityFilter {
    $reflection = new \ReflectionClass(EntityFilter::class);
    /** @var \Drupal\neo_alchemist\Plugin\ComponentFilter\EntityFilter $plugin */
    $plugin = $reflection->newInstanceWithoutConstructor();
    $plugin->setConfiguration($configuration + ['entity_type' => 'node']);

    $filter = $this->createMock(ComponentFilterInterface::class);
    $filter->method('isRequired')->willReturn($required);
    $filter->method('isEditable')->willReturn($editable);
    $filter->method('getValue')->willReturn(NULL);
    $filter->method('getDescription')->willReturn('');
    $filter->method('label')->willReturn('Insights');

    $filterProperty = $reflection->getProperty('filter');
    $filterProperty->setValue($plugin, $filter);

    return $plugin;
  }

  /**
   * Whether buildForm() marked the value element required.
   */
  private function isValueRequired(EntityFilter $plugin, bool $is_default_form): bool {
    $form = $plugin->buildForm([], $this->createMock(FormStateInterface::class), $is_default_form);
    return (bool) $form['value']['#required'];
  }

  /**
   * An autocomplete filter is required on a placement, not on the default.
   */
  #[DataProvider('providerRequiredMatrix')]
  public function testAutocompleteRequiredFlag(bool $required, bool $editable, bool $is_default_form, bool $expected): void {
    $plugin = $this->buildFilter(['field_type' => 'autocomplete'], $required, $editable);

    $this->assertSame($expected, $this->isValueRequired($plugin, $is_default_form));
  }

  /**
   * A select filter follows the same rule as the autocomplete one.
   */
  #[DataProvider('providerRequiredMatrix')]
  public function testSelectRequiredFlag(bool $required, bool $editable, bool $is_default_form, bool $expected): void {
    $plugin = $this->buildFilter(['field_type' => 'select'], $required, $editable);
    $this->attachEmptyEntityStorage($plugin);
    $plugin->setStringTranslation($this->getStringTranslationStub());

    $this->assertSame($expected, $this->isValueRequired($plugin, $is_default_form));
  }

  /**
   * Every widget carries a title, so a required error can be worded.
   *
   * Core can only produce "@name field is required." from an element's
   * #title; an element without one raises an error carrying no message, which
   * surfaced as an empty red box in the editor. The title is invisible
   * because both consuming forms already label the wrapper around it.
   */
  #[DataProvider('providerTitledWidgets')]
  public function testValueElementCarriesAnInvisibleTitle(string $fieldType): void {
    $plugin = $this->buildFilter(['field_type' => $fieldType], TRUE, TRUE);
    $this->attachEmptyEntityStorage($plugin);
    $plugin->setStringTranslation($this->getStringTranslationStub());

    $form = $plugin->buildForm([], $this->createMock(FormStateInterface::class), FALSE);

    $this->assertSame('Insights', (string) $form['value']['#title']);
    $this->assertSame('invisible', $form['value']['#title_display']);
  }

  /**
   * The widget types buildForm() can produce.
   *
   * @return array
   *   Sets of [field_type].
   */
  public static function providerTitledWidgets(): array {
    return [
      'autocomplete' => ['autocomplete'],
      'select' => ['select'],
      'options' => ['options'],
    ];
  }

  /**
   * Stubs the entity type manager so the options query returns nothing.
   *
   * The select branch lists candidate entities before it builds the element;
   * an empty result is enough to reach the '#required' line, and keeps this a
   * unit test.
   */
  private function attachEmptyEntityStorage(EntityFilter $plugin): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn([]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    $property = new \ReflectionProperty(EntityFilter::class, 'entityTypeManager');
    $property->setValue($plugin, $entityTypeManager);
  }

  /**
   * A tagged multi-value autocomplete follows the same rule.
   */
  public function testMultipleAutocompleteIsRequiredOnInstanceForm(): void {
    $plugin = $this->buildFilter([
      'field_type' => 'autocomplete',
      'multiple' => TRUE,
      'multiple_operator' => '+',
    ], TRUE, TRUE);

    $this->assertTrue($this->isValueRequired($plugin, FALSE));
    $this->assertFalse($this->isValueRequired($plugin, TRUE));
  }

  /**
   * The required matrix, shared by every widget type.
   *
   * @return array
   *   Sets of [required, editable, is_default_form, expected].
   */
  public static function providerRequiredMatrix(): array {
    return [
      // The regression: required + editable on a placement's own form.
      'required editable, instance form' => [TRUE, TRUE, FALSE, TRUE],
      // A required filter with no per-placement override has to be filled on
      // the default form, since nothing else can supply it.
      'required locked, default form' => [TRUE, FALSE, TRUE, TRUE],
      'required locked, instance form' => [TRUE, FALSE, FALSE, TRUE],
      // Editable means each placement supplies its own value, so an empty
      // default is legitimate.
      'required editable, default form' => [TRUE, TRUE, TRUE, FALSE],
      // An optional filter is never required anywhere.
      'optional editable, instance form' => [FALSE, TRUE, FALSE, FALSE],
      'optional editable, default form' => [FALSE, TRUE, TRUE, FALSE],
      'optional locked, instance form' => [FALSE, FALSE, FALSE, FALSE],
      'optional locked, default form' => [FALSE, FALSE, TRUE, FALSE],
    ];
  }

}
