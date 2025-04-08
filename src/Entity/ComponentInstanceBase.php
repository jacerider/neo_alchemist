<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;
use Drupal\neo_alchemist\ComponentInstanceInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * A component instance.
 */
abstract class ComponentInstanceBase extends Component implements ComponentInstanceInterface {

  /**
   * The field item.
   *
   * @var \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem
   */
  protected ComponentTreeItem $fieldItem;

  /**
   * The instance values.
   *
   * @var array
   */
  protected ?array $values;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $values, $entity_type) {
    if (empty($values['uuid'])) {
      $values['uuid'] = $this->uuidGenerator()->generate();
    }
    parent::__construct($values, $entity_type);
  }

  /**
   * {@inheritDoc}
   */
  public function isPublished(): bool {
    return $this->isComponentPublished() && ($this->isNew() || $this->getValue('status', TRUE));
  }

  /**
   * {@inheritDoc}
   */
  public function isComponentPublished(): bool {
    return parent::isPublished();
  }

  /**
   * {@inheritDoc}
   */
  public function getTargetEntity(): ContentEntityInterface {
    $entity = $this->getFieldItem()->getEntity();
    if ($entity->isNew() && ($preview = $this->getTargetPreviewEntity())) {
      return $preview;
    }
    return $entity;
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldItem(): ComponentTreeItem {
    return $this->fieldItem;
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldDefinition(): ComponentFieldConfigInterface {
    return $this->fieldItem->getFieldDefinition();
  }

  /**
   * {@inheritdoc}
   */
  public function getValues(): array {
    return $this->values ?? [];
  }

  /**
   * {@inheritDoc}
   */
  public function setValues(array $values): self {
    unset($this->propShapes);
    unset($this->filters);
    $this->values = $values;
    $fieldItem = $this->getFieldItem();
    if ($this->isNew()) {
      $fieldItem->addComponent($this->uuid(), $this->id(), $values);
    }
    else {
      $fieldItem->updateComponent($this->uuid(), $values);
    }
    // Set on entity.
    // We do this as the field item entity may have gotten disconnected. This
    // is currently happening on ajax validation in edit forms.
    $this->getTargetEntity()->set($this->getFieldDefinition()->getName(), $fieldItem->getValue(), FALSE);
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function access($operation, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    // When in preview, all components are allowed to be viewed.
    if ($operation === 'view' && $this->isPreview()) {
      return AccessResult::allowed();
    }
    // Check plugin access.
    $access = $this->checkAccess($operation, $account);
    if ($access->isForbidden()) {
      return $return_as_object ? $access : FALSE;
    }
    // Check field item access.
    $targetEntity = $this->getTargetEntity();
    $targetEntityTypeId = $this->getTargetEntityTypeId();
    $targetEntityBundle = $this->getTargetEntityBundle();
    $access = match(TRUE) {
      $operation === 'create' && $targetEntityTypeId && $targetEntityTypeId !== $targetEntity->getEntityTypeId() => AccessResult::forbidden('Invalid target entity type.'),
      $operation === 'create' && $targetEntityBundle && $targetEntityBundle !== $targetEntity->bundle() => AccessResult::forbidden('Invalid target entity bundle.'),
      default => $this->getFieldItem()->access($operation, $account, TRUE),
    };
    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * {@inheritDoc}
   */
  public function save() {
    return $this->getFieldItem()->saveComponents();
  }

  /**
   * {@inheritDoc}
   */
  public function delete() {
    return $this->getFieldItem()->removeComponent($this->uuid())->saveComponents();
  }

  /**
   * {@inheritdoc}
   */
  public function createDuplicate() {
    $duplicate = clone $this;
    $duplicate->uuid = $this->uuidGenerator()->generate();
    // Automatically add the component.
    $this->getFieldItem()
      ->addComponent($duplicate->uuid(), $duplicate->id(), $duplicate->getValues())
      ->moveComponent($duplicate->uuid(), $this->uuid(), 'after');
    return $duplicate;
  }

}
