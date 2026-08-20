<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Guards which entity fields MatcherField offers as component prop sources.
 *
 * The matcher walks an entity's fields (and, one level down, the fields of
 * anything it references) and offers every property whose DATA TYPE the shape
 * accepts. Data types are coarse — a password's `value` property is a plain
 * `string`, exactly like a title — so without an explicit exclusion every
 * string-accepting prop is offered the user's password hash as a content
 * source.
 *
 * Scope of the exclusion, decided deliberately:
 * - Only genuinely sensitive field TYPES are excluded. Identifiers (id, uuid,
 *   target_id) and system fields (langcode, timezone, roles) are still
 *   offered; they are noisy rather than dangerous, and filtering them would
 *   remove matches components may legitimately use.
 * - Only the OFFER list is filtered. A component already configured against
 *   an excluded field keeps resolving, so tightening this can never silently
 *   blank existing content — see ::testExcludedFieldStillResolves().
 *
 * @see \Drupal\neo_alchemist\Match\MatcherField::EXCLUDED_FIELD_TYPES
 * @see \Drupal\Tests\neo_alchemist\Kernel\MatcherFieldPredicateTest
 */
#[Group('neo_alchemist')]
class MatcherFieldOfferTest extends KernelTestBase {

  /**
   * The saved id of the object-prop probe component.
   *
   * @var string
   */
  private string $objectProbeId;

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

    // A configured content field, so the "legitimate matches survive"
    // assertions have something to find.
    FieldStorageConfig::create([
      'field_name' => 'field_text',
      'entity_type' => 'entity_test',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_text',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Text',
    ])->save();
  }

  /**
   * Returns the match keys offered to the probe component's `text` prop.
   *
   * The entity_test type carries a base `user_id` reference, so the matcher
   * recurses into the user entity — which is where the password field lives.
   */
  private function textPropMatchKeys(): array {
    if (!$this->container->get('entity_type.manager')->getStorage('neo_component')->load('na_match_probe')) {
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
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $storage->resetCache(['na_match_probe']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_match_probe');
    $shape = $component->getPropShapes()['text'];

    return array_keys($this->container->get('neo_alchemist.matcher_field')->getMatches($shape));
  }

  /**
   * A password field is never offered as a prop source.
   *
   * The headline guard. entity_test references a user, so before the
   * exclusion a plain text prop was offered `user_id.pass:value` — the
   * password hash — because the property's data type is `string`.
   */
  public function testPasswordFieldNeverOffered(): void {
    $keys = $this->textPropMatchKeys();

    $offending = array_values(array_filter($keys, static fn (string $key): bool => str_contains($key, 'pass:')));

    $this->assertSame([], $offending, 'No password property may be offered as a component prop source.');
  }

  /**
   * The exclusion follows references, not just the root entity.
   */
  public function testPasswordExcludedThroughReferences(): void {
    $keys = $this->textPropMatchKeys();

    foreach ($keys as $key) {
      $this->assertStringNotContainsString('pass', $key, sprintf('Match "%s" reaches a password field.', $key));
    }
  }

  /**
   * Legitimate content matches are unaffected by the exclusion.
   */
  public function testContentFieldsStillOffered(): void {
    $keys = $this->textPropMatchKeys();

    $this->assertContains('field_text', $keys, 'A configured string field is still offered.');
    $this->assertContains('name', $keys, 'The entity label base field is still offered.');
    $this->assertContains('_entity:label', $keys, 'The dynamic entity label is still offered.');
    $this->assertContains('user_id.name', $keys, 'A referenced entity\'s content field is still offered.');
  }

  /**
   * The options list is filtered too, not just the raw match list.
   *
   * The options list is what the site builder actually sees, so the guard is
   * worthless if it only applies to the raw array behind it.
   */
  public function testOptionsListIsFilteredToo(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $this->textPropMatchKeys();
    $storage->resetCache(['na_match_probe']);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load('na_match_probe');
    $shape = $component->getPropShapes()['text'];

    $options = $this->container->get('neo_alchemist.matcher_field')->getMatchesAsOptions($shape);

    $flattened = [];
    foreach ($options as $group) {
      $flattened += $group;
    }
    foreach (array_keys($flattened) as $key) {
      $this->assertStringNotContainsString('pass', (string) $key, 'The site-builder options list is filtered too.');
    }
  }

  /**
   * CHARACTERIZATION: identifiers and system fields are still offered.
   *
   * The exclusion is deliberately narrow — sensitive field types only. These
   * are noisy rather than dangerous, and removing them could break components
   * that legitimately use them. Recorded so the scope of the decision is
   * explicit rather than looking like an oversight; if the policy is ever
   * widened, this is the test to update.
   */
  public function testIdentifierAndSystemFieldsAreStillOffered(): void {
    $keys = $this->textPropMatchKeys();

    $this->assertContains('id', $keys, 'The entity id is still offered.');
    $this->assertContains('uuid', $keys, 'The uuid is still offered.');
    $this->assertContains('langcode:value', $keys, 'The langcode is still offered.');
  }

  /**
   * An already-configured excluded field still RESOLVES.
   *
   * The exclusion filters the offer list only. A component saved before the
   * exclusion — or written programmatically — keeps working, so tightening
   * the policy can never silently blank existing content. That is the whole
   * reason resolution and offering are separate paths.
   */
  public function testExcludedFieldStillResolves(): void {
    $user = User::create(['name' => 'probe', 'pass' => 'secret-value']);
    $user->save();

    $resolved = $this->container->get('neo_alchemist.matcher_field')
      ->getEntityValue(entity: $user, key: 'pass:value');

    $this->assertNotEmpty($resolved, 'Resolution is not filtered — only the offer list is.');
  }

  /**
   * Returns the match keys offered to an object prop with a numeric child.
   *
   * `box` is {src, alt, width, height} — the same shape as an `image` prop.
   * Its integer children are what an entity reference's integer `target_id`
   * used to collide with, and entity_test's base `user_id` is that reference.
   */
  private function boxPropMatchKeys(): array {
    return array_keys($this->container->get('neo_alchemist.matcher_field')
      ->getMatches($this->boxPropShape()));
  }

  /**
   * The `box` object prop shape of a component targeting entity_test.
   */
  private function boxPropShape(): ComponentShapePluginInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    if (!isset($this->objectProbeId)) {
      $component = Component::create([
        'label' => 'Object match probe fixture',
        'description' => 'Object match probe fixture',
        'component' => 'neo_alchemist_test:na_object_ref_probe',
        'status' => TRUE,
        'target_entity_type' => 'entity_test',
        'target_entity_bundle' => 'entity_test',
      ]);
      // Component::save() re-derives the id from the SDC id, so read it back.
      $component->save();
      $this->objectProbeId = $component->id();
    }
    $storage->resetCache([$this->objectProbeId]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($this->objectProbeId);

    return $component->getPropShapes()['box'];
  }

  /**
   * An entity reference is traversed for an object prop, not claimed whole.
   *
   * ::supportsFieldProperties() decides by data-type overlap, and an entity
   * reference's `target_id` is an integer. That let a bare node reference
   * match any object shape with a numeric child — `field_related_projects`
   * was offered as a source for an `image` prop because a node id and the
   * image's `width`/`height` are all integers.
   *
   * The offer was nonsense on its own, but the damage was that it was
   * exclusive: MatcherField::matchScalar() records a supported field and
   * moves on, so the reference was never recursed into and the fields
   * actually wanted on the far side of it were never offered at all.
   *
   * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginBase::contentBearingFieldProperties()
   */
  public function testEntityReferenceIsTraversedForObjectProps(): void {
    $keys = $this->boxPropMatchKeys();

    $this->assertNotContains('user_id', $keys, 'A bare entity reference is not a source for an object prop.');
    $through = array_values(array_filter($keys, static fn (string $key): bool => str_starts_with($key, 'user_id.')));
    $this->assertNotEmpty($through, 'The reference is recursed into instead, offering the fields behind it.');
  }

  /**
   * A reference that carries its own data still matches on that data.
   *
   * Only the pointer pair (`target_id` + `entity`) is set aside. A field that
   * references something AND stores properties of its own must keep matching
   * on those — core's `image` field feeding an image prop via alt/width/height
   * is the case that matters, and the naive "any field with a reference in it"
   * rule broke it.
   */
  public function testReferenceFieldWithOwnDataStillMatches(): void {
    $definition = $this->container->get('entity_field.manager')
      ->getFieldDefinitions('entity_test', 'entity_test')['user_id'];
    $properties = $definition->getFieldStorageDefinition()->getPropertyDefinitions();

    $shape = $this->boxPropShape();

    $this->assertFalse(
      $shape->supportsFieldProperties($definition, $properties),
      'A pointer-only reference no longer matches on its id.',
    );
    // The same field with a data property alongside the pointer still does.
    $altProperties = $shape->getChildShapes()['alt']->getFieldItem()
      ->getFieldDefinition()->getFieldStorageDefinition()->getPropertyDefinitions();
    $withData = $properties + ['alt' => $altProperties['value']];
    $this->assertTrue(
      $shape->supportsFieldProperties($definition, $withData),
      'A reference that also carries data still matches on that data.',
    );
  }

  /**
   * The langcode field keeps matching even though it carries a DataReference.
   *
   * Its `language` property is a reference, but no entity sits behind it and
   * its `value` is the real content. An exclusion keyed on "has a reference"
   * rather than "has an entity reference" dropped the field entirely.
   */
  public function testLangcodeStillOfferedToObjectProps(): void {
    $keys = $this->boxPropMatchKeys();

    $this->assertContains('langcode', $keys, 'The langcode field is not a pointer and still matches.');
  }

}
