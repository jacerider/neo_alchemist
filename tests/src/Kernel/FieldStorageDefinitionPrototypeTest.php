<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Field\TypedData\FieldItemDataDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\ComponentShapePluginBase;
use Drupal\neo_alchemist\PropSource\FieldStorageDefinition;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the per-field-type storage definition prototype cache.
 *
 * BuildFieldItem() runs once per shape, and a single component tree builds
 * around 70 shapes, so the definition is built once per field type and handed
 * out as a clone. That is only safe while the clones are genuinely independent:
 * every shape stamps its own name, label, description and required flag onto
 * its definition, and getFieldItemList() later stamps the host entity type.
 *
 * The cache is `static`, so leakage would survive between test methods in the
 * same process as well as between shapes in the same request — a corrupted
 * prototype would silently mislabel every subsequent shape of that field type.
 *
 * @see \Drupal\neo_alchemist\ComponentShapePluginBase::createFieldStorageDefinition()
 */
#[Group('neo_alchemist')]
class FieldStorageDefinitionPrototypeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'neo_settings',
    'neo_alchemist',
  ];

  /**
   * Invokes the protected static factory under test.
   *
   * @param string $fieldType
   *   The field type ID.
   *
   * @return \Drupal\neo_alchemist\PropSource\FieldStorageDefinition
   *   A storage definition that must not share state with any other.
   */
  private function create(string $fieldType): FieldStorageDefinition {
    $method = new \ReflectionMethod(ComponentShapePluginBase::class, 'createFieldStorageDefinition');
    return $method->invoke(NULL, $fieldType);
  }

  /**
   * Narrows the item definition to the concrete field item type.
   *
   * GetItemDefinition() is declared as returning the generic
   * DataDefinitionInterface, but the field-item settings and the recursive
   * back-reference asserted below only exist on FieldItemDataDefinition. The
   * instanceof check makes that contract explicit rather than implied.
   *
   * @param \Drupal\neo_alchemist\PropSource\FieldStorageDefinition $definition
   *   The storage definition to read from.
   *
   * @return \Drupal\Core\Field\TypedData\FieldItemDataDefinition
   *   The item definition.
   */
  private function itemDefinition(FieldStorageDefinition $definition): FieldItemDataDefinition {
    $item = $definition->getItemDefinition();
    $this->assertInstanceOf(FieldItemDataDefinition::class, $item);
    return $item;
  }

  /**
   * Each call yields a distinct object graph.
   */
  public function testEachCallReturnsAnUnsharedDefinition(): void {
    $a = $this->create('string');
    $b = $this->create('string');

    $this->assertNotSame($a, $b, 'The prototype itself is never handed out.');
    $this->assertNotSame(
      $this->itemDefinition($a),
      $this->itemDefinition($b),
      'The item definition is deep-cloned, not shared.',
    );
  }

  /**
   * A clone carries the same defaults as a freshly built definition.
   *
   * This is the correctness half of the optimisation: cloning must be
   * indistinguishable from calling FieldStorageDefinition::create().
   */
  public function testCloneMatchesFreshlyBuiltDefinition(): void {
    $cloned = $this->create('string');
    $fresh = FieldStorageDefinition::create('string');

    $this->assertSame($fresh->getType(), $cloned->getType());
    $this->assertEquals(
      $this->itemDefinition($fresh)->getSettings(),
      $this->itemDefinition($cloned)->getSettings(),
    );
    $this->assertSame(
      $this->itemDefinition($fresh)->getClass(),
      $this->itemDefinition($cloned)->getClass(),
    );
    $this->assertSame(
      $this->itemDefinition($fresh)->getDataType(),
      $this->itemDefinition($cloned)->getDataType(),
    );
  }

  /**
   * Stamping one definition never reaches another.
   *
   * This is what buildFieldItem() does to every definition it receives, so a
   * leak here would give two different props the same name and label.
   */
  public function testMutationDoesNotLeakBetweenClones(): void {
    $a = $this->create('string');
    $a->setName('alpha')->setLabel('Alpha')->setDescription('First')->setRequired(TRUE);

    // Stamped with different values rather than left bare: reading getName()
    // on an unstamped definition warns in core, since it indexes a key that
    // was never set.
    $b = $this->create('string');
    $b->setName('beta')->setLabel('Beta')->setDescription('Second')->setRequired(FALSE);

    $this->assertSame('alpha', $a->getName(), 'The first clone kept its own values.');
    $this->assertSame('Alpha', (string) $a->getLabel());
    $this->assertSame('First', (string) $a->getDescription());
    $this->assertTrue($a->isRequired());

    $this->assertSame('beta', $b->getName());
    $this->assertSame('Beta', (string) $b->getLabel());
    $this->assertSame('Second', (string) $b->getDescription());
    $this->assertFalse($b->isRequired());
  }

  /**
   * A clone taken after heavy mutation is still pristine.
   *
   * Directly targets prototype pollution: if any of the stamping above had
   * reached the cached prototype, this definition would arrive pre-stamped.
   */
  public function testPrototypeSurvivesMutationOfEarlierClones(): void {
    $mutated = $this->create('string');
    $mutated->setName('polluter')->setRequired(TRUE);
    $mutatedItem = $this->itemDefinition($mutated);
    $mutatedItem->setSettings(['polluted' => TRUE] + $mutatedItem->getSettings());

    $fresh = $this->create('string');

    $this->assertFalse($fresh->isRequired(), 'Required did not carry over.');
    $this->assertArrayNotHasKey('polluted', $this->itemDefinition($fresh)->getSettings());
    $this->assertEquals(
      $this->itemDefinition(FieldStorageDefinition::create('string'))->getSettings(),
      $this->itemDefinition($fresh)->getSettings(),
      'Settings still match a definition built from scratch.',
    );
  }

  /**
   * Item-definition settings are isolated too, not just the outer object.
   *
   * BuildFieldItem() calls setSettings() on the item definition whenever a
   * shape supplies storage or instance settings, so this is the mutation most
   * likely to leak if the clone were shallow.
   */
  public function testItemDefinitionSettingsAreIsolated(): void {
    $a = $this->create('string');
    $itemA = $this->itemDefinition($a);
    $itemA->setSettings(['leaked' => TRUE] + $itemA->getSettings());

    $b = $this->create('string');

    $this->assertArrayNotHasKey('leaked', $this->itemDefinition($b)->getSettings());
  }

  /**
   * The item definition points back at its own clone, not the prototype.
   *
   * FieldItemDataDefinition holds a recursive reference to its parent, and
   * buildFieldItem() reads it back via $fieldItem->getFieldDefinition() to
   * stamp the prop's name. If it still pointed at the prototype, every shape
   * would be stamping the shared object.
   */
  public function testItemDefinitionBackReferenceFollowsTheClone(): void {
    $definition = $this->create('string');

    $this->assertSame($definition, $this->itemDefinition($definition)->getFieldDefinition());

    $definition->setName('beta');
    $this->assertSame('beta', $this->itemDefinition($definition)->getFieldDefinition()->getName());
  }

  /**
   * Different field types get their own prototypes.
   */
  public function testDistinctFieldTypesAreCachedSeparately(): void {
    $string = $this->create('string');
    $integer = $this->create('integer');

    $this->assertSame('string', $string->getType());
    $this->assertSame('integer', $integer->getType());
    $this->assertNotSame(
      $this->itemDefinition($string)->getClass(),
      $this->itemDefinition($integer)->getClass(),
    );
  }

}
