<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\neo_alchemist\Entity\ComponentEntity;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The component tells the editor whether its inspector would show a field.
 *
 * A canvas click passes through a component that offers the current user
 * nothing to edit and lands on the first one that does. The client cannot
 * work that out itself: whether a prop is editable is the site builder's
 * setting and the account's access, both server-side. So the component emits
 * one boolean, `editable`, beside its op records.
 *
 * @see \Drupal\neo_alchemist\Entity\Component::hasInspectorFields()
 */
#[Group('neo_alchemist')]
class ComponentEmitsEditableFlagTest extends HybridFieldKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('entity_type.manager')->clearCachedDefinitions();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * The seed leaf, which sits in the entity's customizable region.
   */
  private function leaf(): ComponentEntity {
    $entity = $this->createTestEntity();
    $item = $entity->get(static::FIELD_NAME)->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $item);
    $instance = $item->getComponent(static::SEED_UUID);
    $this->assertInstanceOf(ComponentEntity::class, $instance);
    return $instance;
  }

  /**
   * The flag as the editor reads it, off the data-component attribute.
   */
  private function emitted(ComponentEntity $instance): mixed {
    $instance->setPreview(TRUE);
    $build = $instance->toRenderable(FALSE, FALSE);
    $data = Json::decode($build['#props']['attributes']->offsetGet('data-component')->value());
    $this->assertArrayHasKey('editable', $data, 'The flag reaches the attribute.');
    return $data['editable'];
  }

  /**
   * A component with a prop the user may edit is editable.
   */
  public function testComponentWithAnEditablePropIsEditable(): void {
    $leaf = $this->leaf();
    $this->assertTrue($leaf->hasInspectorFields());
    $this->assertTrue($this->emitted($leaf));
  }

  /**
   * A component whose every prop is locked offers nothing, and says so.
   */
  public function testComponentWithOnlyLockedPropsIsNotEditable(): void {
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_leaf')
      ->set('settings.props.text.editable', FALSE)
      ->save();
    $this->container->get('entity_type.manager')->getStorage('neo_component')->resetCache();

    $leaf = $this->leaf();
    $this->assertFalse($leaf->hasInspectorFields());
    $this->assertFalse($this->emitted($leaf));
  }

}
