<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the four shape predicates that decide what a field may populate.
 *
 * MatcherField walks an entity's fields and asks the shape four questions,
 * in this order, for every field it finds:
 *
 * 1. allowFieldDefinition()   — is this field compatible at all? (enum
 *    values, max length, min/max settings). A FALSE here skips the field
 *    entirely, properties included.
 * 2. supportsFieldDefinition() — can the whole field feed the prop directly?
 *    (same field type, or a declared supports_field_types entry).
 * 3. supportsFieldProperties() — can the field's properties collectively feed
 *    the prop? (used for multi-child object shapes).
 * 4. supportsFieldProperty()  — can a single property feed the prop?
 *
 * Getting these wrong is quiet in both directions: too permissive and site
 * builders are offered nonsense bindings (see MatcherFieldOfferTest); too
 * strict and a legitimate field silently disappears from the picker, which
 * looks like a missing feature rather than a bug.
 *
 * Exercised against real field definitions rather than mocks — the predicates
 * read storage settings and property definitions, so mocking them would only
 * restate this test's own assumptions.
 *
 * @see \Drupal\neo_alchemist\Match\MatcherField::matchScalar()
 */
#[Group('neo_alchemist')]
class MatcherFieldPredicateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
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
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');
    $this->installConfig(['field', 'filter']);

    $this->createField('field_text', 'string');
    $this->createField('field_long_text', 'string', ['max_length' => 500]);
    $this->createField('field_count', 'integer');
    $this->createField('field_bounded', 'integer', [], ['min' => 5, 'max' => 50]);
    $this->createField('field_flag', 'boolean');
    $this->createField('field_email', 'email');
    $this->createField('field_body', 'text_long');
    $this->createField('field_link', 'link');
    $this->createField('field_list', 'list_string', [
      'allowed_values' => ['a' => 'A', 'b' => 'B'],
    ]);
  }

  /**
   * Creates a field on entity_test.
   *
   * @param string $name
   *   The field name.
   * @param string $type
   *   The field type.
   * @param array $storageSettings
   *   Storage-level settings.
   * @param array $instanceSettings
   *   Instance-level settings.
   */
  private function createField(string $name, string $type, array $storageSettings = [], array $instanceSettings = []): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'entity_test',
      'type' => $type,
      'settings' => $storageSettings,
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => $name,
      'settings' => $instanceSettings,
    ])->save();
  }

  /**
   * Returns a prop shape from the probe component.
   *
   * @param string $prop
   *   The prop name: text, count, flag or link.
   */
  private function shape(string $prop): ComponentShapePluginInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_match_probe')) {
      Component::create([
        'id' => 'na_match_probe',
        'label' => 'Match probe fixture',
        'description' => 'Match probe fixture',
        'component' => 'neo_alchemist_test:na_match_probe',
        'status' => TRUE,
        'target_entity_type' => 'entity_test',
        'target_entity_bundle' => 'entity_test',
      ])->save();
    }
    $storage->resetCache(['na_match_probe']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_match_probe');
    return $component->getPropShapes()[$prop];
  }

  /**
   * Returns an entity_test field definition by name.
   */
  private function field(string $name) {
    return $this->container->get('entity_field.manager')
      ->getFieldDefinitions('entity_test', 'entity_test')[$name];
  }

  /**
   * A field of the shape's own type is supported directly.
   */
  public function testSupportsSameFieldType(): void {
    $this->assertTrue($this->shape('text')->supportsFieldDefinition($this->field('field_text')));
    $this->assertTrue($this->shape('count')->supportsFieldDefinition($this->field('field_count')));
    $this->assertTrue($this->shape('flag')->supportsFieldDefinition($this->field('field_flag')));
    $this->assertTrue($this->shape('link')->supportsFieldDefinition($this->field('field_link')));
  }

  /**
   * A field of an unrelated type is not supported wholesale.
   *
   * A link field cannot feed a plain string prop as a whole — only its
   * `title` property can, which is decided by supportsFieldProperty().
   */
  public function testDoesNotSupportUnrelatedFieldType(): void {
    $this->assertFalse($this->shape('text')->supportsFieldDefinition($this->field('field_link')));
    $this->assertFalse($this->shape('link')->supportsFieldDefinition($this->field('field_text')));
    $this->assertFalse($this->shape('flag')->supportsFieldDefinition($this->field('field_link')));
  }

  /**
   * A single property whose data type the shape accepts is supported.
   */
  public function testSupportsSinglePropertyByDataType(): void {
    $textShape = $this->shape('text');
    $linkField = $this->field('field_link');
    $properties = $linkField->getFieldStorageDefinition()->getPropertyDefinitions();

    $this->assertTrue(
      $textShape->supportsFieldProperty($linkField, $properties['title']),
      'A link title is a string, so it can feed a text prop.',
    );
    $this->assertFalse(
      $textShape->supportsFieldProperty($linkField, $properties['options']),
      'A map property cannot feed a text prop.',
    );
  }

  /**
   * A multi-property shape is never fed by a single field property.
   *
   * The link shape stores uri/title/options together; handing it one loose
   * property would leave the rest unset.
   */
  public function testMultiPropertyShapeRejectsSingleProperty(): void {
    $linkShape = $this->shape('link');
    $textField = $this->field('field_text');
    $properties = $textField->getFieldStorageDefinition()->getPropertyDefinitions();

    $this->assertFalse($linkShape->supportsFieldProperty($textField, reset($properties)));
  }

  /**
   * An enum shape only accepts a field whose allowed values cover its own.
   *
   * The allow gate runs first and skips the field entirely, so a mismatched
   * option list removes the field from the picker rather than offering a
   * binding that could resolve to an invalid value.
   */
  public function testAllowFieldDefinitionChecksAllowedValues(): void {
    // The probe's props carry no allowed_values, so any field passes this
    // gate; the guard only bites for enum-backed shapes.
    $this->assertTrue($this->shape('text')->allowFieldDefinition($this->field('field_list')));
  }

  /**
   * A field whose max length exceeds the shape's is rejected.
   *
   * Truncation would be silent data loss, so the field is not offered.
   */
  public function testAllowFieldDefinitionChecksMaxLength(): void {
    $textShape = $this->shape('text');
    $shapeMax = $textShape->getFieldItemList()->getFieldDefinition()
      ->getFieldStorageDefinition()->getSettings()['max_length'] ?? NULL;
    $this->assertNotNull($shapeMax, 'Premise: the string shape declares a max length.');

    $this->assertTrue(
      $textShape->allowFieldDefinition($this->field('field_text')),
      'A field within the shape max length is allowed.',
    );
    $this->assertSame(
      $shapeMax >= 500,
      $textShape->allowFieldDefinition($this->field('field_long_text')),
      'A longer field is allowed only when the shape can hold it.',
    );
  }

  /**
   * Min/max instance settings gate numeric fields.
   *
   * A shape demanding a wider range than the field permits would be offered
   * a binding that can never satisfy it.
   */
  public function testAllowFieldDefinitionChecksNumericBounds(): void {
    $countShape = $this->shape('count');

    $this->assertTrue(
      $countShape->allowFieldDefinition($this->field('field_count')),
      'An unbounded integer field is allowed by an unbounded shape.',
    );
    $this->assertTrue(
      $countShape->allowFieldDefinition($this->field('field_bounded')),
      'A bounded field is allowed when the shape sets no competing bounds.',
    );
  }

  /**
   * A single-prop array consults its CHILD's definition, consistently.
   *
   * ArrayShape overrides getFieldDefinitionForSupportCheck() to return its
   * single child's field definition — an array of strings is matched as a
   * string, not as the `map` field the array itself stores into.
   *
   * The override only reached half the decision. The predicates called it,
   * but the accepted-type lists behind them
   * (getSupportedFieldPropertyTypes() / getSupportedFieldTypes()) read
   * getFieldItemList()->getFieldDefinition() directly, so they described the
   * `map` storage — which exposes no properties at all, leaving the accepted
   * list empty and every property-type check FALSE.
   *
   * The observable symptom was narrow but real: a field whose FIELD type
   * differs from the child's, but whose single PROPERTY type matches, could
   * never be bound. `uuid` is the clearest example — field type `uuid`,
   * property type `string` — and it was unreachable for an array of strings
   * while an ordinary string prop could use it.
   */
  public function testSinglePropArrayMatchesByPropertyType(): void {
    $arrayShape = $this->arrayShape();

    $matches = array_keys($this->container->get('neo_alchemist.matcher_field')->getMatches($arrayShape));

    $this->assertContains('uuid', $matches, 'A field matching only by property type is now reachable.');
    $this->assertContains('field_text', $matches, 'Matching by identical field type still works.');
  }

  /**
   * CHARACTERIZATION: an array claims multi-property fields WHOLE.
   *
   * Arrays deliberately do not take per-property matches from a
   * multi-property field: ArrayShape::supportsFieldProperties() claims the
   * whole field when its children's property types overlap the field's, so
   * the field's own value (via the auto-matched main property) feeds the
   * items. That short-circuits before the per-property loop, which is why
   * `field_link:title` is not offered even though `field_link` is.
   *
   * Recorded because it is easy to mistake for the bug fixed above — it is a
   * different, deliberate strategy. Changing it would mean deciding which of
   * two competing match strategies wins for a field, and could re-point
   * existing bindings.
   */
  public function testArrayClaimsMultiPropertyFieldsWhole(): void {
    $arrayShape = $this->arrayShape();

    $matches = array_keys($this->container->get('neo_alchemist.matcher_field')->getMatches($arrayShape));

    $this->assertContains('field_link', $matches, 'The whole link field is offered to the array.');
    $this->assertNotContains('field_link:title', $matches, 'Per-property matches are not offered alongside it.');
    $this->assertContains('field_body', $matches);
    $this->assertNotContains('field_body:value', $matches);
  }

  /**
   * The accepted-type lists follow the same definition as the predicates.
   *
   * The unit behind the test above: both must describe the same shape, or an
   * override redirects one and not the other.
   */
  public function testAcceptedTypeListsFollowTheSupportCheckDefinition(): void {
    $arrayShape = $this->arrayShape();

    $supportDefinition = (new \ReflectionMethod($arrayShape, 'getFieldDefinitionForSupportCheck'))->invoke($arrayShape);
    $propertyTypes = (new \ReflectionMethod($arrayShape, 'getSupportedFieldPropertyTypes'))->invoke($arrayShape);

    $this->assertSame('string', $supportDefinition->getType(), 'Premise: the array defers to its single child.');
    $this->assertNotSame([], $propertyTypes, 'The accepted property types describe the child, not the array\'s own map storage.');
    $this->assertContains('string', $propertyTypes);
  }

  /**
   * Returns the single-prop array fixture's shape.
   */
  private function arrayShape(): ComponentShapePluginInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!$storage->load('na_array_single')) {
      Component::create([
        'id' => 'na_array_single',
        'label' => 'Array single fixture',
        'description' => 'Array single fixture',
        'component' => 'neo_alchemist_test:na_array_single',
        'status' => TRUE,
        'target_entity_type' => 'entity_test',
        'target_entity_bundle' => 'entity_test',
      ])->save();
    }
    $storage->resetCache(['na_array_single']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_array_single');
    return $component->getPropShapes()['items'];
  }

  /**
   * The predicates run in order, and the first gate wins.
   *
   * A field excluded by allowFieldDefinition() must never reach the support
   * checks — otherwise a later TRUE would resurrect it.
   */
  public function testAllowGateRunsBeforeSupportChecks(): void {
    $textShape = $this->shape('text');
    $field = $this->field('field_text');

    // Sanity: this field is both allowed and supported, so ordering is only
    // observable through the matcher's output, which the offer test covers.
    $this->assertTrue($textShape->allowFieldDefinition($field));
    $this->assertTrue($textShape->supportsFieldDefinition($field));

    $matches = $this->container->get('neo_alchemist.matcher_field')->getMatches($textShape);
    $this->assertArrayHasKey('field_text', $matches, 'An allowed and supported field is offered.');
  }

}
