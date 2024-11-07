<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Component\Utility\NestedArray;
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
  public function getEntity(): ContentEntityInterface {
    return $this->getFieldItem()->getEntity();
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
   * {@inheritdoc}
   */
  public function getValue($key, mixed $default = NULL): mixed {
    $exists = NULL;
    $values = $this->getValues();
    $value = NestedArray::getValue($values, (array) $key, $exists);
    return $exists ? $value : $default;
  }

  /**
   * {@inheritDoc}
   */
  public function setValues(array $values): self {
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
    $this->getEntity()->set($this->getFieldDefinition()->getName(), $fieldItem->getValue(), FALSE);
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function access($operation, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $access = match(TRUE) {
      $operation === 'update' && !$this->isComponentPublished() => AccessResult::forbidden('Component is unpublished globally.'),
      $operation === 'sort' && !$this->isComponentPublished() => AccessResult::forbidden('Component is unpublished globally.'),
      default => $this->getEntity()->access($operation, $account, TRUE),
    };
    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * {@inheritDoc}
   */
  public function save() {
    return 1;
  }

  /**
   * {@inheritDoc}
   */
  public function delete() {
  }

}
