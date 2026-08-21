<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\neo_alchemist\ComponentFieldConfigInterface;
use Drupal\neo_alchemist\Routing\EditorOp;
use Drupal\neo_alchemist\ComponentInstanceInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentSizesInterface;
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
   * A representative preview entity, or FALSE once resolved to none.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface|bool|null
   */
  protected ContentEntityInterface|bool|null $previewFallbackEntity = NULL;

  /**
   * {@inheritDoc}
   */
  public function getTargetEntity(): ContentEntityInterface {
    $entity = $this->getFieldItem()->getEntity();
    if ($entity->isNew()) {
      $preview = $this->getTargetPreviewEntity();
      if (!$preview && $this->isPreview()) {
        // No preview entity was explicitly chosen. Let modules supply a
        // representative saved entity (e.g. a sample taxonomy term of the
        // field's hierarchy level) so a field-config preview renders against
        // real content instead of an empty placeholder.
        $preview = $this->resolvePreviewFallbackEntity($entity);
      }
      if ($preview) {
        return $preview;
      }
    }
    return $entity;
  }

  /**
   * Resolves a representative preview entity for a placeholder host entity.
   *
   * A field-config preview has no real host entity — only a new placeholder of
   * the target bundle. This lets modules swap in a representative saved entity
   * via hook_neo_alchemist_preview_entity_alter() so the preview reflects real
   * content. The result is cached for the life of the instance.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $placeholder
   *   The new placeholder host entity.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   A representative saved entity, or NULL when none was supplied.
   */
  protected function resolvePreviewFallbackEntity(ContentEntityInterface $placeholder): ?ContentEntityInterface {
    if ($this->previewFallbackEntity === NULL) {
      $this->previewFallbackEntity = FALSE;
      $preview = NULL;
      $context = [
        'component' => $this,
        'field_definition' => $this->getFieldDefinition(),
        'placeholder' => $placeholder,
      ];
      \Drupal::moduleHandler()->alter('neo_alchemist_preview_entity', $preview, $context);
      if ($preview instanceof ContentEntityInterface && !$preview->isNew()) {
        $this->previewFallbackEntity = $preview;
      }
    }
    return $this->previewFallbackEntity ?: NULL;
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
   *
   * An add op targets the field's library route with this instance as the
   * before/after sibling; a move op carries the direction as a route parameter;
   * every verb op names its own route. Each instance's toUrl() already resolves
   * the host scope it belongs to (entity, field-UI or block), so this one
   * grammar produces the correct URL in every scope.
   */
  protected function editorOpUrl(EditorOp $op): ?Url {
    return match (TRUE) {
      $op->position !== NULL => $this->toUrl('library', ['query' => [$op->position => $this->uuid()]]),
      $op->direction !== NULL => $this->toUrl('move', ['direction' => $op->direction]),
      default => $this->toUrl($op->rel),
    };
  }

  /**
   * Extracts the move op's direction from a toUrl() options array.
   *
   * The move route carries the direction as a path parameter, but toUrl()'s
   * only channel for extra input is the options array. This pulls it out — so
   * the caller sets it as a route parameter and it is not forwarded to the URL
   * generator as a stray option — and requires it: a move with no direction is
   * a caller bug, and failing here names it rather than letting route
   * generation raise an opaque "missing parameter direction" later. The value
   * itself (up/down) is the tree operation's business, not this generator's, so
   * it is not constrained here.
   *
   * @param array $options
   *   The toUrl() options, passed by reference; the 'direction' key is removed.
   *
   * @return string
   *   The move direction.
   */
  protected static function takeMoveDirection(array &$options): string {
    $direction = $options['direction'] ?? NULL;
    unset($options['direction']);
    if (!is_string($direction) || $direction === '') {
      throw new \InvalidArgumentException("The move op requires a non-empty 'direction' option.");
    }
    return $direction;
  }

  /**
   * {@inheritDoc}
   */
  public function setParent(?string $parentUuid, ?string $slot = NULL): self {
    if ($parentUuid || $slot) {
      assert($parentUuid && $slot, 'Both parentUuid and slot must be provided together.');
      $parentComponent = $this->getFieldItem()->getComponent($parentUuid);
      if (!$parentComponent) {
        return $this;
      }
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
  public function access($operation, ?AccountInterface $account = NULL, $return_as_object = FALSE, ?ComponentShapePluginInterface $parentShape = NULL): bool|AccessResult {
    // When in preview, all components are allowed to be viewed.
    if ($operation === 'view' && $this->isPreview()) {
      return $return_as_object ? AccessResult::allowed() : TRUE;
    }
    if ($this->getFieldItem()->isHybridScope()) {
      $hybridAccess = $this->checkHybridAccess($operation);
      if ($hybridAccess->isForbidden()) {
        return $return_as_object ? $hybridAccess : FALSE;
      }
    }
    if ($operation === 'create') {
      $size = $this->getSize();
      if (!$this->getFieldItem()->allowSize($size)) {
        return $return_as_object ? AccessResult::forbidden('Size not allowed in field.') : FALSE;
      }
      $targetEntity = $this->getTargetEntity();
      $targetEntityTypeId = $this->getTargetEntityTypeId();
      $targetEntityBundle = $this->getTargetEntityBundle();
      if ($targetEntityTypeId && $targetEntityTypeId !== $targetEntity->getEntityTypeId()) {
        return $return_as_object ? AccessResult::forbidden('Invalid target entity type.') : FALSE;
      }
      if ($targetEntityBundle && $targetEntityBundle !== $targetEntity->bundle()) {
        return $return_as_object ? AccessResult::forbidden('Invalid target entity bundle.') : FALSE;
      }
      if ($parentShape) {
        if ($parentShape instanceof ComponentSizesInterface) {
          if (!$parentShape->allowSize($size)) {
            return $return_as_object ? AccessResult::forbidden('Size not allowed in parent shape.') : FALSE;
          }
        }
        foreach ($this->getPropShapesAll() as $shape) {
          $access = $shape->access('create_nested', $account, TRUE);
          if ($access->isForbidden()) {
            return $return_as_object ? $access : FALSE;
          }
        }
      }
    }
    // Check plugin access.
    $access = $this->checkAccess($operation, $account);
    if ($access->isForbidden()) {
      return $return_as_object ? $access : FALSE;
    }
    // Check field item access.
    $access = $this->getFieldItem()->access($operation, $account, TRUE);
    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * Checks hybrid-mode access for an operation on this instance.
   *
   * In hybrid mode the field default layout is authoritative for the
   * structure: inherited instances are locked and components may only be
   * added within entity-customizable regions.
   *
   * @param string $operation
   *   The operation.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Forbidden when the operation is not allowed in hybrid mode, neutral
   *   otherwise.
   */
  protected function checkHybridAccess(string $operation): AccessResult {
    if ($operation === 'create') {
      // Adding a new component (library candidates, the add route) and the
      // add-before/add-after ops on existing instances both target the
      // instance's own section: it must be an entity-customizable region.
      if (!$this->getFieldItem()->isCustomTarget($this->parentUuid, $this->parentSlot)) {
        return AccessResult::forbidden('Components may only be added within an entity-customizable region.');
      }
      return AccessResult::neutral();
    }
    if (in_array($operation, ['update', 'delete', 'clone', 'sort', 'manage_value'], TRUE) && $this->isInherited()) {
      return AccessResult::forbidden('This component is inherited from the field default layout.');
    }
    return AccessResult::neutral();
  }

  /**
   * {@inheritDoc}
   */
  public function isInherited(): bool {
    $fieldItem = $this->getFieldItem();
    if ($this->isNew() || !$fieldItem->isHybridScope()) {
      return FALSE;
    }
    return $fieldItem->isInheritedInstance($this->uuid());
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
