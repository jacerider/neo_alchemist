<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\neo_alchemist\Entity\NeoFieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use PHPUnit\Framework\Attributes\Group;

/**
 * Deleting a component updates its host field configs instead of deleting them.
 *
 * A field's default layout bakes component ids into settings.defaults.tree, so
 * the field config really does depend on those components. Declaring that
 * dependency is only half the job: ConfigEntityBase::preDelete() deletes every
 * dependent it cannot fix, and deleting a field config takes every entity's
 * stored values with it. NeoFieldConfig::onDependencyRemoval() is what turns
 * that deletion into an update.
 *
 * The class has to be the one field_config itself uses, because the config
 * dependency system loads dependents straight from storage — a subclass used
 * only for field definitions would never be asked.
 *
 * @see \Drupal\neo_alchemist\Entity\NeoFieldConfig
 * @see \Drupal\Core\Config\Entity\ConfigEntityBase::preDelete()
 */
#[Group('neo_alchemist')]
final class ComponentDefaultLayoutDependencyTest extends HybridFieldKernelTestBase {

  /**
   * The field config's config name.
   */
  private const FIELD_CONFIG_ID = 'entity_test.entity_test.' . self::FIELD_NAME;

  /**
   * Loads the field config straight from storage, as the config system does.
   */
  private function loadFieldConfig(): ?FieldConfig {
    $storage = $this->container->get('entity_type.manager')->getStorage('field_config');
    $storage->resetCache([self::FIELD_CONFIG_ID]);
    return $storage->load(self::FIELD_CONFIG_ID);
  }

  /**
   * The component ids the field config declares a dependency on.
   *
   * @return string[]
   *   The component ids, sorted.
   */
  private function declaredComponentIds(): array {
    $prefix = 'neo_alchemist.neo_component.';
    $ids = [];
    foreach ($this->loadFieldConfig()->getDependencies()['config'] ?? [] as $name) {
      if (str_starts_with($name, $prefix)) {
        $ids[] = substr($name, strlen($prefix));
      }
    }
    sort($ids);
    return $ids;
  }

  /**
   * Deletes a component config entity.
   */
  private function deleteComponent(string $id): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $component = $storage->load($id);
    $this->assertNotNull($component, "Fixture component $id exists.");
    $component->delete();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Field configs are loaded as the class carrying the dependency behaviour.
   */
  public function testFieldConfigStorageUsesNeoFieldConfig(): void {
    $this->assertInstanceOf(NeoFieldConfig::class, $this->loadFieldConfig());
  }

  /**
   * Components in a default layout become declared config dependencies.
   */
  public function testDefaultLayoutComponentsAreDeclaredAsDependencies(): void {
    $this->assertSame(['na_leaf', 'na_region_host'], $this->declaredComponentIds());
  }

  /**
   * A field with no Alchemist default layout declares no component deps.
   */
  public function testNonAlchemistFieldDeclaresNoComponentDependencies(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_plain',
      'entity_type' => 'entity_test',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_plain',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();

    $plain = $this->container->get('entity_type.manager')
      ->getStorage('field_config')
      ->load('entity_test.entity_test.field_plain');
    foreach ($plain->getDependencies()['config'] ?? [] as $name) {
      $this->assertStringStartsNotWith('neo_alchemist.neo_component.', $name);
    }
  }

  /**
   * Deleting a nested component updates the field rather than deleting it.
   */
  public function testDeletingNestedComponentUpdatesTheFieldConfig(): void {
    $this->deleteComponent('na_leaf');

    $field = $this->loadFieldConfig();
    $this->assertNotNull($field, 'The field config survived the component deletion.');

    $defaults = $field->getSetting('defaults');
    $this->assertSame(
      [['uuid' => self::HOST_UUID, 'component' => 'na_region_host']],
      $defaults['tree'][ComponentTreeStructure::ROOT_UUID],
      'The host is still placed.'
    );
    $this->assertArrayNotHasKey(
      self::HOST_UUID,
      $defaults['tree'],
      'The host subtree is dropped once its only populated slot is emptied.'
    );
    $this->assertArrayNotHasKey(self::SEED_UUID, $defaults['props']);
    $this->assertArrayHasKey(self::HOST_UUID, $defaults['props']);
    $this->assertSame(['na_region_host'], $this->declaredComponentIds());
  }

  /**
   * Deleting a parent component takes its nested instances with it.
   */
  public function testDeletingHostComponentRemovesItsSubtree(): void {
    $this->deleteComponent('na_region_host');

    $field = $this->loadFieldConfig();
    $this->assertNotNull($field, 'The field config survived the component deletion.');

    $defaults = $field->getSetting('defaults');
    $this->assertSame(
      [ComponentTreeStructure::ROOT_UUID => []],
      $defaults['tree'],
      'The root key stays even with nothing left in it.'
    );
    $this->assertSame([], $defaults['props']);
    $this->assertSame([], $this->declaredComponentIds());
  }

  /**
   * The stored field data and the field storage both survive the deletion.
   */
  public function testEntityDataSurvivesComponentDeletion(): void {
    $entity = $this->createTestEntity();
    $this->deleteComponent('na_leaf');

    $this->assertNotNull(
      $this->container->get('entity_type.manager')->getStorage('field_storage_config')
        ->load('entity_test.' . self::FIELD_NAME),
      'The field storage was not cascaded away.'
    );
    $this->assertNotNull(
      $this->container->get('entity_type.manager')->getStorage('entity_test')->load($entity->id()),
      'The host entity still exists.'
    );
  }

}
