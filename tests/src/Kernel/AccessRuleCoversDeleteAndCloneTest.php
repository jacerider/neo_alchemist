<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\Entity\ComponentEntity;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * An access rule that denies "Create or Remove" denies delete and clone.
 *
 * Access rules are consulted for three ops: view, update and create, the last
 * labelled "Create or Remove". The editor asks about delete and clone by
 * their own names, which used to fall outside those three and so past every
 * rule: a Protected component could not be edited, but could be deleted or
 * duplicated by the same user. Both now answer to the create rule. Moving
 * (sort) deliberately still does not.
 *
 * @see \Drupal\neo_alchemist\Entity\Component::checkAccess()
 */
#[Group('neo_alchemist')]
class AccessRuleCoversDeleteAndCloneTest extends HybridFieldKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['user']);
    // Burn uid 1, which passes every permission check.
    $this->createUser();
  }

  /**
   * The seed leaf, which sits in the entity's customizable region.
   */
  private function leaf(): ComponentEntity {
    $this->container->get('entity_type.manager')->getStorage('neo_component')->resetCache();
    $entity = $this->createTestEntity();
    $item = $entity->get(static::FIELD_NAME)->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $item);
    $instance = $item->getComponent(static::SEED_UUID);
    $this->assertInstanceOf(ComponentEntity::class, $instance);
    return $instance;
  }

  /**
   * Puts the Protected rule on the leaf's component.
   */
  private function protect(): void {
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_leaf')
      ->set('settings.access.11111111-2222-4333-8444-555555555555', [
        'plugin_id' => 'protected',
        'plugin_settings' => [],
      ])
      ->save();
  }

  /**
   * An editor of the host entity, with or without the protected permission.
   */
  private function editor(bool $trusted): AccountInterface {
    $permissions = ['administer entity_test content', 'view test entity'];
    if ($trusted) {
      $permissions[] = 'use protected components';
    }
    return $this->createUser($permissions);
  }

  /**
   * Without the permission, a protected component is not deleted or cloned.
   */
  public function testProtectedDeniesDeleteAndClone(): void {
    $editor = $this->editor(FALSE);
    $this->assertTrue($this->leaf()->access('delete', $editor), 'Premise: delete is allowed before the rule.');
    $this->assertTrue($this->leaf()->access('clone', $editor), 'Premise: clone is allowed before the rule.');
    $this->assertTrue($this->leaf()->access('sort', $editor), 'Premise: sort is allowed before the rule.');

    $this->protect();
    $leaf = $this->leaf();
    $this->assertFalse($leaf->access('delete', $editor), 'The rule denies delete.');
    $this->assertFalse($leaf->access('clone', $editor), 'The rule denies clone.');
    $this->assertTrue($leaf->access('sort', $editor), 'Moving is still allowed.');
  }

  /**
   * With the permission, nothing changes.
   */
  public function testProtectedLeavesTrustedUsersAlone(): void {
    $this->protect();
    $editor = $this->editor(TRUE);
    $leaf = $this->leaf();
    $this->assertTrue($leaf->access('delete', $editor));
    $this->assertTrue($leaf->access('clone', $editor));
  }

}
