<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
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
 * @see \Drupal\neo_alchemist\MatcherField::EXCLUDED_FIELD_TYPES
 * @see \Drupal\Tests\neo_alchemist\Kernel\MatcherFieldPredicateTest
 */
#[Group('neo_alchemist')]
class MatcherFieldOfferTest extends KernelTestBase {

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

}
