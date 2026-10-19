<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * The children-match view-access policy and "drop the item when empty".
 *
 * The fixture is a two-level list: the host references groups, and every group
 * references items. entity_test's access handler forbids `view` on any entity
 * labelled `forbid_access`, which is the denied case throughout.
 *
 * @see \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper::fetchValues()
 */
#[Group('neo_alchemist')]
class ChildrenMatchAccessTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The label entity_test's access handler always forbids.
   */
  private const FORBIDDEN = 'forbid_access';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
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
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('entity_test_rev');

    $this->addReferenceField('entity_test', 'field_groups', 'entity_test_rev');
    $this->addReferenceField('entity_test_rev', 'field_items', 'entity_test');

    // Not uid 1, so nothing short-circuits the access handler.
    $this->setUpCurrentUser(['uid' => 2], ['view test entity']);
  }

  /**
   * Adds an unlimited entity reference field to an entity_test type.
   */
  private function addReferenceField(string $entityTypeId, string $fieldName, string $targetType): void {
    FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entityTypeId,
      'type' => 'entity_reference',
      'settings' => ['target_type' => $targetType],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => $entityTypeId,
      'bundle' => $entityTypeId,
      'label' => $fieldName,
    ])->save();
  }

  /**
   * Builds the groups → items component bound to a host.
   *
   * @param bool $access
   *   The provider's shape_access setting.
   * @param bool $dropEmpty
   *   Whether a group with no items left is dropped.
   * @param array $groups
   *   Group label => list of item labels.
   * @param array|null $accessProps
   *   Props for a prop_value access rule, or NULL for none.
   *
   * @return \Drupal\neo_alchemist\Entity\Component
   *   The reloaded component, its target entity bound.
   */
  private function buildComponent(bool $access, bool $dropEmpty, array $groups, ?array $accessProps = NULL): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $component = Component::create([
      'label' => 'Access tree fixture',
      'description' => 'Access tree fixture',
      'component' => 'neo_alchemist_test:na_access_tree',
      'status' => TRUE,
      'target_entity_type' => 'entity_test',
    ]);
    $component->save();
    $id = $component->id();

    $items = [
      'field' => '_reference~field_items:entity',
      'shape_fields' => ['title' => ['field' => 'name']],
    ];
    if ($dropEmpty) {
      $items['drop_empty'] = TRUE;
    }
    $config = $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.' . $id)
      ->set('settings.props.groups.plugins.groups', [
        'entity_reference' => [
          'id' => 'entity_reference',
          'settings' => [
            'entity' => 'field_groups:entity',
            'shape_fields' => [
              'title' => ['field' => 'name'],
              'items' => $items,
            ],
            'shape_published' => TRUE,
            'shape_access' => $access,
          ],
        ],
      ]);
    if ($accessProps !== NULL) {
      $config->set('settings.access', [
        'test-prop-value' => [
          'plugin_id' => 'prop_value',
          'plugin_settings' => ['props' => $accessProps],
        ],
      ]);
    }
    $config->save();
    $storage->resetCache([$id]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($id);

    $groupIds = [];
    foreach ($groups as $groupLabel => $itemLabels) {
      $itemIds = [];
      foreach ($itemLabels as $itemLabel) {
        $item = EntityTest::create(['name' => $itemLabel]);
        $item->save();
        $itemIds[] = $item->id();
      }
      $group = EntityTestRev::create(['name' => $groupLabel, 'field_items' => $itemIds]);
      $group->save();
      $groupIds[] = $group->id();
    }
    $host = EntityTest::create(['name' => 'HOST', 'field_groups' => $groupIds]);
    $host->save();
    $this->assertTrue($component->setTargetPreviewEntity((string) $host->id()), 'The host binds as the target entity.');
    return $component;
  }

  /**
   * The resolved groups prop, flattened to JSON for label assertions.
   */
  private function groupsJson(Component $component): string {
    return (string) json_encode($component->getPropValues()['groups'] ?? []);
  }

  /**
   * Off by default: a forbidden entity is still listed, at both levels.
   */
  public function testAccessOffKeepsForbiddenEntities(): void {
    $component = $this->buildComponent(FALSE, FALSE, [
      'GROUP OPEN' => ['ITEM OPEN', self::FORBIDDEN],
      self::FORBIDDEN => ['ITEM UNDER FORBIDDEN GROUP'],
    ]);

    $json = $this->groupsJson($component);
    $this->assertStringContainsString(self::FORBIDDEN, $json, 'Without the policy, forbidden entities are listed.');
    $this->assertStringContainsString('ITEM UNDER FORBIDDEN GROUP', $json);
  }

  /**
   * On: forbidden entities are left out at the root and at nested levels.
   */
  public function testAccessDropsForbiddenEntitiesAtEveryLevel(): void {
    $component = $this->buildComponent(TRUE, FALSE, [
      'GROUP OPEN' => ['ITEM OPEN', self::FORBIDDEN],
      self::FORBIDDEN => ['ITEM UNDER FORBIDDEN GROUP'],
    ]);

    $json = $this->groupsJson($component);
    $this->assertStringContainsString('GROUP OPEN', $json);
    $this->assertStringContainsString('ITEM OPEN', $json);
    $this->assertStringNotContainsString(self::FORBIDDEN, $json, 'A forbidden group and a forbidden nested item are both left out.');
    $this->assertStringNotContainsString('ITEM UNDER FORBIDDEN GROUP', $json, 'A forbidden group takes its items with it.');
    $this->assertStringNotContainsString('EXAMPLE', $json, 'No schema example leaks in.');
  }

  /**
   * The access results' cacheability reaches the component.
   *
   * entity_test grants view by permission, so the decision varies by
   * user.permissions — and it must, or one visitor's filtered list would be
   * served to another.
   */
  public function testAccessCacheabilityBubbles(): void {
    $component = $this->buildComponent(TRUE, FALSE, [
      'GROUP OPEN' => ['ITEM OPEN'],
    ]);
    $component->getPropValues();

    $this->assertContains('user.permissions', $component->getCacheableMetadata()->getCacheContexts());
  }

  /**
   * A group whose items were all filtered out is dropped when asked.
   */
  public function testDropEmptyRemovesParentOfAFullyFilteredList(): void {
    $groups = [
      'GROUP EMPTIED' => [self::FORBIDDEN],
      'GROUP KEPT' => ['ITEM OPEN'],
    ];

    $kept = $this->groupsJson($this->buildComponent(TRUE, FALSE, $groups));
    $this->assertStringContainsString('GROUP EMPTIED', $kept, 'Without drop_empty the emptied group stays.');

    $dropped = $this->groupsJson($this->buildComponent(TRUE, TRUE, $groups));
    $this->assertStringNotContainsString('GROUP EMPTIED', $dropped, 'With drop_empty the emptied group is dropped.');
    $this->assertStringContainsString('GROUP KEPT', $dropped);
    $this->assertStringContainsString('ITEM OPEN', $dropped);
  }

  /**
   * With everything filtered away, a prop_value rule hides the component.
   */
  public function testPropValueHidesTheComponentWhenEverythingIsFiltered(): void {
    $component = $this->buildComponent(TRUE, TRUE, [
      'GROUP EMPTIED' => [self::FORBIDDEN],
      self::FORBIDDEN => ['ITEM UNDER FORBIDDEN GROUP'],
    ], ['groups']);

    $instance = array_values($component->getAccessInstances())[0];
    $this->assertTrue($instance->access('view', $this->container->get('current_user'))->isForbidden(), 'Nothing left to list: the component is hidden.');
  }

  /**
   * The same rule leaves the component visible while something survives.
   */
  public function testPropValueKeepsTheComponentWhileSomethingSurvives(): void {
    $component = $this->buildComponent(TRUE, TRUE, [
      'GROUP EMPTIED' => [self::FORBIDDEN],
      'GROUP KEPT' => ['ITEM OPEN'],
    ], ['groups']);

    $instance = array_values($component->getAccessInstances())[0];
    $this->assertFalse($instance->access('view', $this->container->get('current_user'))->isForbidden());
  }

}
