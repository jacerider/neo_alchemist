<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;
use Drupal\neo_alchemist\ComponentInstanceInterface;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
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
   * The parent UUID.
   *
   * @var string|null
   */
  protected ?string $parentUuid = NULL;

  /**
   * The parent slot (region prop).
   *
   * @var string|null
   */
  protected ?string $parentSlot = NULL;

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
   * {@inheritDoc}
   */
  public function setParent(?string $parentUuid, ?string $slot = NULL): self {
    if ($parentUuid || $slot) {
      assert($parentUuid && $slot, 'Both parentUuid and slot must be provided together.');
      $parentComponent = $this->getFieldItem()->getComponent($parentUuid);
      assert($parentComponent instanceof ComponentInstanceInterface, 'Parent component must be a valid ComponentInstanceInterface.');
      // Currently we use component shapes as slots.
      $allShapes = $parentComponent->getPropShapesAll(NULL, TRUE);
      $shape = $allShapes[$slot] ?? NULL;
      assert($shape !== NULL, 'The specified shape must exist on the parent component.');
      assert($shape->getRef() === 'region', 'The specified shape must be of type region.');
    }
    $this->setParentUuid($parentUuid);
    $this->setParentSlot($slot);
    return $this;
  }

  /**
   * Sets the parent UUID for the component instance.
   *
   * @param string|null $parentUuid
   *   The parent UUID to set, or NULL to unset.
   *
   * @return self
   *   The current instance of the component.
   */
  protected function setParentUuid(?string $parentUuid): self {
    $this->parentUuid = $parentUuid ?: NULL;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getParentUuid(): ?string {
    return $this->parentUuid ?: ComponentTreeStructure::ROOT_UUID;
  }

  /**
   * Sets the parent slot (region prop).
   *
   * @param string|null $parentSlot
   *   The slot.
   *
   * @return $this
   *   The current instance.
   */
  protected function setParentSlot(?string $parentSlot): self {
    $this->parentSlot = $parentSlot ?: NULL;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getParentSlot(): ?string {
    return $this->parentSlot;
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
      $fieldItem->addComponent($this->uuid(), $this->id(), $values, $this->getParentUuid(), $this->getParentSlot());
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
    $fieldItem = $this->getFieldItem();
    return $fieldItem->cloneComponent($this);
  }

}
