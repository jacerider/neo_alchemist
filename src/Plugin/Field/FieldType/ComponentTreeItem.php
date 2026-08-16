<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\Field\FieldType;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RenderableInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\Url;
use Drupal\neo_alchemist\ComponentInstanceInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentSizesInterface;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\neo_alchemist\Entity\ComponentFieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentPropsValues;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeHydrated;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList;

/**
 * Plugin implementation of the 'component_tree' field type.
 *
 * @todo Implement PreconfiguredFieldUiOptionsInterface?
 * @todo How to achieve https://www.previousnext.com.au/blog/pitchburgh-diaries-decoupled-layout-builder-sprint-1-2?
 * @see https://git.drupalcode.org/project/metatag/-/blob/2.0.x/src/Plugin/Field/FieldType/MetatagFieldItem.php
 */
#[FieldType(
  id: "neo_component_tree",
  label: new TranslatableMarkup("Alchemist"),
  description: new TranslatableMarkup("Field to store Alchemist components."),
  default_formatter: "neo_component_tree",
  constraints: [
    'ValidComponentTree' => [],
  ],
  column_groups: [
    'props' => [
      'label' => new TranslatableMarkup('Component property values'),
      'translatable' => TRUE,
    ],
    'tree' => [
      'label' => new TranslatableMarkup('Component tree'),
      'translatable' => TRUE,
    ],
  ],
  cardinality: 1,
  list_class: NeoComponentTreeList::class,
)]
class ComponentTreeItem extends FieldItemBase implements RenderableInterface, ComponentSizesInterface {

  /**
   * The data definition.
   *
   * @var \Drupal\Core\Field\TypedData\FieldItemDataDefinition
   */
  protected $definition;

  /**
   * Flag to indicate if the item is a draft.
   *
   * @var bool
   */
  protected $draft = FALSE;

  /**
   * Flag to indicate if the item is in preview mode.
   *
   * @var bool
   */
  protected bool $preview = FALSE;

  /**
   * The Neo component.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface[]
   */
  protected array $components = [];

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      'allow_custom' => FALSE,
      'sizes' => [],
      'defaults' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::fieldSettingsForm($form, $form_state);
    $settings = $this->getSettings();

    $element['allow_custom'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow each entity to have its layout customized.'),
      '#default_value' => $settings['allow_custom'] ?? FALSE,
    ];

    // Turning customization off is silent and lossy-looking: the customized
    // layouts stop rendering but stay in the database, invisible. Say so
    // before the save, while it is still a choice.
    $definition = $this->getFieldDefinition();
    if (!empty($settings['allow_custom']) && $definition->getTargetBundle()) {
      $stored = \Drupal::service('neo_alchemist.inert_component_data')->countStored(
        $definition->getTargetEntityTypeId(),
        $definition->getTargetBundle(),
        $definition->getName(),
      );
      if ($stored) {
        $element['allow_custom_warning'] = [
          '#type' => 'container',
          '#states' => [
            'visible' => [
              ':input[name="settings[allow_custom]"]' => ['checked' => FALSE],
            ],
          ],
          'message' => [
            '#theme' => 'status_messages',
            '#message_list' => [
              'warning' => [
                $this->formatPlural(
                  $stored,
                  '1 entity has a customized layout. Turning this off means the default layout renders instead — the customized one is kept but never shown, and turning this back on would restore it. To delete it for good, use "Purge stored data" on this field\'s Layout page after saving.',
                  '@count entities have customized layouts. Turning this off means the default layout renders instead — the customized ones are kept but never shown, and turning this back on would restore them. To delete them for good, use "Purge stored data" on this field\'s Layout page after saving.',
                ),
              ],
            ],
          ],
        ];
      }
    }

    $element['sizes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Supported component sizes'),
      '#description' => $this->t('Select the sizes that can be assigned to regions in this field. If no sizes are selected, all sizes will be available.'),
      '#options' => self::getSizeOptions(),
      '#default_value' => $settings['sizes'] ?? [],
      '#element_validate' => [[__CLASS__, 'validateSizes']],
      '#tooltip' => FALSE,
    ];

    $element['defaults'] = [
      '#type' => 'value',
      '#value' => $settings['defaults'] ?? [],
    ];

    return $element;
  }

  /**
   * Validates the sizes selection.
   */
  public static function validateSizes(array $element, FormStateInterface $form_state) {
    $form_state->setValue($element['#parents'], array_filter($form_state->getValue($element['#parents'])));
  }

  /**
   * {@inheritdoc}
   */
  public static function fieldSettingsSummary(FieldDefinitionInterface $field_definition): array {
    $summary = parent::fieldSettingsSummary($field_definition);
    $settings = $field_definition->getSettings();
    $summary[] = new FormattableMarkup('Allow customization: @value', [
      '@value' => !empty($settings['allow_custom']) ? 'Yes' : 'No',
    ]);
    if (!empty($settings['sizes'])) {
      $sizes = self::getSizeOptions();
      $size_options = [];
      foreach ($settings['sizes'] as $size) {
        $size_options[] = $sizes[$size] ?? $size;
      }
      $summary[] = new FormattableMarkup('Supported sizes: @sizes', [
        '@sizes' => implode(', ', $size_options),
      ]);
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'tree' => [
          'description' => 'The component tree structure.',
          'type' => 'json',
          'pgsql_type' => 'jsonb',
          'mysql_type' => 'json',
          'sqlite_type' => 'json',
          'not null' => FALSE,
        ],
        'props' => [
          'description' => 'The prop values for each component in the component tree.',
          'type' => 'json',
          'pgsql_type' => 'jsonb',
          'mysql_type' => 'json',
          'sqlite_type' => 'json',
          'not null' => FALSE,
        ],
      ],
      'indexes' => [],
      'foreign keys' => [
        // @todo Add the "hash" part the proposal at https://www.drupal.org/project/drupal/issues/3440578
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName() {
    return 'tree';
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['tree'] = DataDefinition::create('neo_component_tree_structure')
      ->setLabel(new TranslatableMarkup('A component tree without props values.'))
      ->setRequired(TRUE);

    $properties['props'] = DataDefinition::create('neo_component_props_values')
      ->setLabel(new TranslatableMarkup('Prop values for each component in the component tree'))
      ->setRequired(TRUE);

    $properties['hydrated'] = DataDefinition::create('neo_component_tree_hydrated')
      ->setLabel(new TranslatableMarkup('The hydrated component tree: structure + props values combined — provides render tree for client side or render array for server side.'))
      ->setComputed(TRUE)
      ->setInternal(FALSE)
      ->setReadOnly(TRUE)
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * Gets the URL object for the item.
   *
   * @param string $rel
   *   The link relationship type, for example: canonical or edit-form. If none
   *   is provided, canonical is assumed, or edit-form if no canonical link
   *   exists.
   * @param array $options
   *   See \Drupal\Core\Routing\UrlGeneratorInterface::generateFromRoute() for
   *   the available options.
   *
   * @return \Drupal\Core\Url
   *   The URL object.
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Drupal\Core\Entity\Exception\UndefinedLinkTemplateException
   */
  public function toUrl($rel = NULL, array $options = []): Url {
    $fieldName = $this->getFieldDefinition()->getName();
    if ($this->belongsToFieldConfig()) {
      return $this->getFieldDefinition()->toUrl($rel, $options);
    }
    $fieldKey = ComponentFieldConfig::getKeyFromFieldname($fieldName);
    return match($rel) {
      'collection' => $this->getEntity()->toUrl(),
      'preview' => $this->getEntity()->toUrl("alchemist.preview")->setRouteParameter('neo_field', $fieldKey),
      'library' => $this->getEntity()->toUrl("alchemist.library")->setRouteParameter('neo_field', $fieldKey),
      'add' => $this->getEntity()->toUrl("alchemist.add")->setRouteParameter('neo_field', $fieldKey),
      'publish' => $this->getEntity()->toUrl("alchemist.publish")->setRouteParameter('neo_field', $fieldKey),
      'revert' => $this->getEntity()->toUrl("alchemist.revert")->setRouteParameter('neo_field', $fieldKey),
      'reset' => $this->getEntity()->toUrl("alchemist.reset")->setRouteParameter('neo_field', $fieldKey),
      'sort' => $this->getEntity()->toUrl("alchemist.sort")->setRouteParameter('neo_field', $fieldKey),
      default => $this->getEntity()->toUrl("alchemist.manage")->setRouteParameter('neo_field', $fieldKey)
    };
  }

  /**
   * {@inheritDoc}
   */
  public function access($operation, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $account ?? \Drupal::currentUser();
    if ($operation === 'publish') {
      $entity = $this->getEntity();
      $allow = $this->hasDraft();
      if (!$allow && $entity instanceof RevisionableInterface) {
        $allow = !$entity->isDefaultRevision();
      }
      $access = AccessResult::allowedIf($allow)->andIf($this->getEntity()->access('update', $account, TRUE));
    }
    else {
      $access = match(TRUE) {
        $operation === 'create' && $this->belongsToFieldConfig() => AccessResult::allowedIfHasPermission($account, 'administer ' . $this->getEntity()->getEntityTypeId() . ' fields'),
        $operation === 'create' => AccessResult::allowedIf($this->getSetting('allow_custom') || $this->getFieldDefinition()->isHybrid())->andIf($this->getEntity()->access('update', $account, TRUE)),
        $operation === 'revert' => AccessResult::allowedIf($this->hasDraft())->andIf($this->getEntity()->access('update', $account, TRUE)),
        $operation === 'reset' => AccessResult::allowedIf(!$this->belongsToFieldConfig() && !$this->getParent()->isDefault())->andIf($this->getEntity()->access('update', $account, TRUE)),
        // Purging stored entity data is a field-administration act, not an
        // edit of any one entity, and it only means anything while the field
        // is locked — that is the only mode whose rows nothing reads back.
        // Deliberately no row count here: whether data exists decides whether
        // the BUTTON shows, not whether the route is permitted.
        $operation === 'purge' => AccessResult::allowedIf(
          $this->belongsToFieldConfig()
          && !$this->getSetting('allow_custom')
          && !$this->getFieldDefinition()->isHybrid()
        )->andIf($this->getFieldDefinition()->access('update', $account, TRUE)),
        // Restructuring the tree — reordering, removing or duplicating an
        // instance — is an edit of the host entity, never a delete of it and
        // never an operation the host defines itself. Left to the default arm,
        // 'delete' would demand node-delete permission just to drop one
        // component, and 'clone' is not an entity operation at all, so the host
        // returns neutral and only accounts that bypass access entirely ever
        // see the button.
        in_array($operation, ['sort', 'delete', 'clone'], TRUE) => $this->getEntity()->access('update', $account, TRUE),
        default => $this->getEntity()->access($operation, $account, TRUE),
      };
    }
    return $return_as_object ? $access : $access->isAllowed();
  }

  /**
   * Sets the preview status of the items.
   *
   * @param bool $preview
   *   The preview status to set.
   *
   * @return $this
   *   The current instance of the component.
   */
  public function setPreview(bool $preview): self {
    $this->preview = $preview;
    return $this;
  }

  /**
   * Checks if the component is in preview mode.
   *
   * @return bool
   *   TRUE if the component is in preview mode, FALSE otherwise.
   */
  public function isPreview(): bool {
    return $this->preview;
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\neo_alchemist\ComponentFieldConfigInterface
   *   The field configuration.
   */
  public function getFieldDefinition() {
    return $this->definition->getFieldDefinition();
  }

  /**
   * {@inheritdoc}
   *
   * @return \Drupal\neo_alchemist\Plugin\Field\NeoComponentTreeList
   *   The field item list.
   */
  public function getParent() {
    return $this->parent;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    // If either `tree` or `props` is not set, consider this not empty, because
    // it is not empty in a *valid* way. If considered empty, the
    // NotNullConstraintValidator would apply some magic that prevents detailed
    // validation.
    // @see \Drupal\Core\Validation\Plugin\Validation\Constraint\NotNullConstraintValidator::validate()
    if ($this->get('tree')->getValue() === NULL || $this->get('props')->getValue() === NULL) {
      return FALSE;
    }

    $tree = $this->get('tree')->getValue();
    return $tree === '' || $tree === Json::encode([]);
  }

  /**
   * {@inheritdoc}
   */
  public function toArray() {
    // Return the raw property values, avoid the magic of parent Map::toArray().
    // This is necessary to allow validating a component tree in detail.
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator::validate()
    return $this->values;
  }

  /**
   * Generates an array of options for a given parent UUID.
   *
   * This method retrieves the component tree structure and iterates through the
   * components associated with the specified parent UUID. It loads each
   * component and adds its label to the options array.
   *
   * @param string|null $parentUuid
   *   The UUID of the parent component. Defaults to the root UUID.
   * @param string|null $slot
   *   The slot within the parent component. If provided, only components within
   *   that slot will be included.
   *
   * @return array
   *   An associative array where the keys are component UUIDs and the values
   *   are component labels.
   */
  public function toOptions(?string $parentUuid = ComponentTreeStructure::ROOT_UUID, ?string $slot = NULL) {
    if ($parentUuid !== ComponentTreeStructure::ROOT_UUID && $slot === NULL) {
      throw new \InvalidArgumentException('When sorting on a non-root parent, a slot is required.');
    }
    if (empty($parentUuid)) {
      $parentUuid = ComponentTreeStructure::ROOT_UUID;
    }
    $options = [];
    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    foreach ($tree->getComponentsBySection($parentUuid, $slot) as $key => $data) {
      $instance = $this->getComponent($data['uuid']);
      if ($instance) {
        $options[$data['uuid']] = $instance->label();
      }
    }
    return $options;
  }

  /**
   * Checks if the item belongs to a field config.
   *
   * @return bool
   *   TRUE if the item belongs to an actual entity, FALSE otherwise.
   */
  public function belongsToFieldConfig(): bool {
    return $this->getParent()->belongsToFieldConfig();
  }

  /**
   * Checks if the item is the default value.
   *
   * @return bool
   *   TRUE if the item is the default value, FALSE otherwise.
   */
  protected function isDefault(): bool {
    return $this->getParent()->isDefault();
  }

  /**
   * Retrieves the class name of the component instance.
   *
   * @return string
   *   The fully qualified class name of the component instance.
   */
  protected function getComponentInstanceClass(): string {
    if ($this->belongsToFieldConfig()) {
      return 'Drupal\neo_alchemist\Entity\ComponentField';
    }
    return 'Drupal\neo_alchemist\Entity\ComponentEntity';
  }

  /**
   * Create a new component instance.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $neoComponent
   *   The Neo component.
   * @param string|null $parentUuid
   *   The parent UUID.
   * @param string|null $slot
   *   The slot.
   *
   * @return \Drupal\neo_alchemist\ComponentInstanceInterface
   *   The component instance.
   */
  public function createComponent(ComponentInterface $neoComponent, ?string $parentUuid = NULL, ?string $slot = NULL): ComponentInstanceInterface {
    $value = $neoComponent->toArray();
    // Clear UUID so that a new one will be generated on creation.
    $value['uuid'] = NULL;
    $value['fieldItem'] = $this;
    $entity_class = $this->getComponentInstanceClass();
    $instance = new $entity_class($value, 'neo_component');
    if ($parentUuid && $slot) {
      $instance->setParent($parentUuid, $slot);
    }
    $instance->enforceIsNew();
    return $instance;
  }

  /**
   * Clone a component instance along with its children.
   *
   * @param \Drupal\neo_alchemist\ComponentInstanceInterface $component
   *   The component instance to clone.
   *
   * @return $this
   *   The current instance of the component tree item.
   */
  public function cloneComponent(ComponentInstanceInterface $component): ComponentInstanceInterface {
    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    $parentUuid = $component->getParentUuid();
    $slot = $component->getParentSlot();
    $cloned = $this->createComponent($component, $parentUuid, $slot);
    $this->addComponent($cloned->uuid(), $cloned->id(), $component->getValues(), $parentUuid, $slot);
    $this->moveComponent($cloned->uuid(), $component->uuid(), 'after', $parentUuid, $slot);

    // Clone the full subtree from a one-time snapshot of the tree. The
    // snapshot is safe because every clone gets a fresh uuid, so the adds
    // below never touch the source sections being iterated. Reading the
    // source's own sections (rather than getComponentsBySection() with the
    // slot the source happens to SIT IN) is what keeps each child in its own
    // slot — the old lookup threw whenever the two slot names differed and
    // only ever cloned one level, silently dropping grandchildren.
    $treeData = Json::decode($tree->getValue() ?? '') ?: [];
    $this->cloneComponentChildren($treeData, $component->uuid(), $cloned->uuid());

    return $cloned;
  }

  /**
   * Recursively clones a component's children under a cloned parent.
   *
   * @param array $treeData
   *   A decoded snapshot of the tree, taken before any clones were added.
   * @param string $sourceUuid
   *   The uuid of the component whose children are being cloned.
   * @param string $targetUuid
   *   The uuid of the freshly cloned parent receiving the copies.
   */
  protected function cloneComponentChildren(array $treeData, string $sourceUuid, string $targetUuid): void {
    foreach ((array) ($treeData[$sourceUuid] ?? []) as $slotName => $tuples) {
      foreach ((array) $tuples as $tuple) {
        $childUuid = $tuple['uuid'] ?? NULL;
        $child = $childUuid ? $this->getComponent($childUuid) : NULL;
        if (!$child) {
          continue;
        }
        $clonedChild = $this->createComponent($child, $targetUuid, (string) $slotName);
        $this->addComponent($clonedChild->uuid(), $clonedChild->id(), $child->getValues(), $targetUuid, (string) $slotName);
        $this->cloneComponentChildren($treeData, $childUuid, $clonedChild->uuid());
      }
    }
  }

  /**
   * Add a component to the component tree.
   *
   * @param string $uuid
   *   The UUID of the component instance.
   * @param string $neoComponentId
   *   The ID of the component.
   * @param array $propValues
   *   The prop values for the component instance.
   * @param string $parentUuid
   *   The UUID of the parent component instance.
   * @param string|null $slot
   *   The slot ID when not appending to the root.
   *
   * @return $this
   */
  public function addComponent(string $uuid, string $neoComponentId, array $propValues = [], string $parentUuid = ComponentTreeStructure::ROOT_UUID, $slot = NULL): self {
    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    $props = $this->get('props');
    assert($props instanceof ComponentPropsValues);
    $tree->addComponent($uuid, $neoComponentId, $parentUuid, $slot);
    $props->setComponent($uuid, $propValues);
    return $this;
  }

  /**
   * Retrieves a Neo component instance.
   *
   * @param string $uuid
   *   The UUID.
   *
   * @return \Drupal\neo_alchemist\ComponentInstanceInterface|null
   *   The Neo component instance.
   */
  public function getComponent(string $uuid): ?ComponentInstanceInterface {
    $key = $uuid . ':' . $this->getParent()->getScope();
    if (!isset($this->components[$key])) {
      $tree = $this->get('tree');
      assert($tree instanceof ComponentTreeStructure);
      $props = $this->get('props');
      assert($props instanceof ComponentPropsValues);
      $id = $tree->getComponentId($uuid);
      if ($id) {
        $neoComponent = Component::load($id);
        if ($neoComponent) {
          $value = $neoComponent->toArray();
          $value['uuid'] = $uuid;
          $value['fieldItem'] = $this;
          $value['values'] = $props->getComponentPropsSources($uuid);
          $entity_class = $this->getComponentInstanceClass();
          $instance = new $entity_class($value, 'neo_component');
          $instance->setParent($tree->getComponentParentUuid($uuid), $tree->getComponentSlot($uuid));
          $instance->setPreview($this->isPreview());
        }
      }
      $this->components[$key] = $instance ?? NULL;
    }
    return $this->components[$key];
  }

  /**
   * Retrieves the component instances.
   *
   * @param string $parentUuid
   *   The UUID of the parent section. Defaults to the root section.
   *
   * @return \Drupal\neo_alchemist\ComponentInstanceInterface[]
   *   An array of component instances.
   */
  public function getComponents(string $parentUuid = ComponentTreeStructure::ROOT_UUID): array {
    $components = [];
    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    foreach ($tree->getComponentsBySection($parentUuid) as $data) {
      if ($component = $this->getComponent($data['uuid'])) {
        $components[$data['uuid']] = $component;
      }
    }
    return $components;
  }

  /**
   * Checks if the component with the given UUID exists in the tree.
   *
   * @param string $uuid
   *   The UUID of the component to check.
   *
   * @return bool
   *   TRUE if the component exists in the tree, FALSE otherwise.
   */
  public function hasComponent(string $uuid): bool {
    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    return in_array($uuid, $tree->getComponentInstanceUuids());
  }

  /**
   * Sorts the components within the tree structure.
   *
   * @param array $componentInstanceIds
   *   An array of component instance UUIDs to be sorted.
   * @param string $parentUuid
   *   (optional) The UUID of the parent component. Defaults to the root UUID.
   * @param mixed $slot
   *   (optional) The slot within the parent component where the components
   *   should be sorted.
   */
  public function sortComponents(array $componentInstanceIds, string $parentUuid = ComponentTreeStructure::ROOT_UUID, $slot = NULL): self {
    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    if (empty($parentUuid)) {
      $parentUuid = ComponentTreeStructure::ROOT_UUID;
    }
    $tree->sortComponents($componentInstanceIds, $parentUuid, $slot);
    return $this;
  }

  /**
   * Moves a component to a new position within the component tree.
   *
   * @param string $uuid
   *   The UUID of the component to move.
   * @param string $positionUuid
   *   The UUID of the component that determines the new position.
   * @param string $position
   *   The position relative to the $positionUuid component. Can be 'before' or
   *   'after'. Defaults to 'after'.
   * @param string $parentUuid
   *   The UUID of the parent component. Defaults to the root UUID.
   * @param mixed $slot
   *   An optional slot identifier.
   *
   * @return self
   *   Returns the current instance for method chaining.
   */
  public function moveComponent(string $uuid, string $positionUuid, string $position = 'after', string $parentUuid = ComponentTreeStructure::ROOT_UUID, $slot = NULL): self {
    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    $componentInstanceIds = $tree->getComponentInstanceUuids($parentUuid, $slot);
    if (in_array($uuid, $componentInstanceIds)) {
      // Remove the UUID from its current position.
      $componentInstanceIds = array_values(array_diff($componentInstanceIds, [$uuid]));

      if ($position === 'before') {
        $beforeIndex = array_search($positionUuid, $componentInstanceIds);
        if ($beforeIndex !== FALSE) {
          $componentInstanceIds = array_merge(
            array_slice($componentInstanceIds, 0, $beforeIndex),
            [$uuid],
            array_slice($componentInstanceIds, $beforeIndex)
          );
        }
      }
      elseif ($position === 'after') {
        $afterIndex = array_search($positionUuid, $componentInstanceIds);
        if ($afterIndex !== FALSE) {
          $componentInstanceIds = array_merge(
            array_slice($componentInstanceIds, 0, $afterIndex + 1),
            [$uuid],
            array_slice($componentInstanceIds, $afterIndex + 1)
          );
        }
      }
      $this->sortComponents($componentInstanceIds, $parentUuid, $slot);
    }
    return $this;
  }

  /**
   * Update the prop values for a component instance.
   *
   * @param string $uuid
   *   The UUID of the component instance.
   * @param array $propValues
   *   The prop values for the component instance.
   *
   * @return $this
   */
  public function updateComponent(string $uuid, array $propValues): self {
    $props = $this->get('props');
    assert($props instanceof ComponentPropsValues);
    $props->setComponent($uuid, $propValues);
    return $this;
  }

  /**
   * Remove a component from the component tree.
   *
   * @param string $uuid
   *   The UUID of the component instance.
   *
   * @return $this
   */
  public function removeComponent(string $uuid): self {
    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    $props = $this->get('props');
    assert($props instanceof ComponentPropsValues);
    // Collect the whole subtree before the tree drops it: props are keyed by
    // instance UUID with no parent links, so once the sections are gone there
    // is nothing left to work out which prop values belonged underneath.
    $removed = $tree->getSubtreeUuids($uuid);
    $tree->removeComponent($uuid);
    foreach ($removed as $removedUuid) {
      $props->removeComponent($removedUuid);
    }
    return $this;
  }

  /**
   * Resets the components by setting the parent value to NULL.
   *
   * @return self
   *   The current instance of the class for method chaining.
   */
  public function resetComponents(): self {
    $this->getParent()->setValue(NULL);
    return $this;
  }

  /**
   * Saves the components.
   *
   * When saving existing entities, the entity is assumed to be complete,
   * partial updates of entities are not supported.
   *
   * @return int
   *   Either SAVED_NEW or SAVED_UPDATED, depending on the operation performed.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   *   In case of failures an exception is thrown.
   */
  public function saveComponents(): int {
    // Remove non-existing components.
    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);

    foreach ($tree->getComponentInstanceUuids() as $uuid) {
      if (!$this->getComponent($uuid)) {
        $this->removeComponent($uuid);
      }
    }

    if ($this->belongsToFieldConfig()) {
      return (int) $this->getFieldDefinition()->setComponentValuesFromFieldItem($this)->save();
    }
    else {
      if ($this->isDraft()) {
        $this->setDraftValue($this->getValue());
        return SAVED_UPDATED;
      }
      $this->deleteDraft();
      $entity = $this->getEntity();
      $fieldName = $this->getFieldDefinition()->getName();
      $list = $this->getParent();
      if ($entity->get($fieldName) !== $list) {
        // This item belongs to a detached copy created by the route param
        // converter (typically carrying a committed draft): carry the copy's
        // value onto the entity before persisting. A hybrid list flagged as
        // default (reset) syncs as NULL so the entity returns to the field
        // default — its value holds the default layout, which must not be
        // persisted as entity-owned. Everything else syncs the list value
        // (empty after a non-hybrid reset).
        $empty = $list instanceof NeoComponentTreeList && $list->isHybridScope() && $list->isDefault();
        $entity->set($fieldName, $empty ? NULL : $list->getValue(), FALSE);
      }
      if ($entity instanceof EntityChangedInterface) {
        // Update the changed time to ensure that the entity is marked as
        // updated.
        $entity->setChangedTime(\Drupal::time()->getRequestTime());
      }
      return (int) $entity->save();
    }
  }

  /**
   * Generates a draft key for the current entity and field definition.
   *
   * The draft key is a string composed of the 'neo_alchemist' prefix, the
   * entity ID, and the field name, concatenated with dots.
   *
   * @param string|null $uuid
   *   (optional) The UUID of the component instance.
   *
   * @return string
   *   The generated draft key.
   *
   * @throws \AssertionError
   *   Thrown if the field does not belong to a field configuration.
   */
  public function getDraftKey(?string $uuid = NULL): string {
    $props = [
      'neo_alchemist',
      $this->belongsToFieldConfig() ? $this->getFieldDefinition()->getTargetEntityTypeId() : $this->getEntity()->id(),
      $this->getFieldDefinition()->getName(),
    ];
    if ($uuid) {
      $props[] = $uuid;
    }
    return implode('.', $props);
  }

  /**
   * Retrieves the draft value of the component tree item.
   *
   * @return array|null
   *   The draft value as an array, or NULL if no draft value is set.
   */
  public function getDraftValue(): ?array {
    return $this->getState()->get($this->getDraftKey());
  }

  /**
   * Sets the draft value for the component tree item.
   *
   * @param array $value
   *   The draft value to be set.
   *
   * @return self
   *   The current instance of the component tree item.
   */
  public function setDraftValue(array $value): self {
    $this->getState()->set($this->getDraftKey(), $value);
    return $this;
  }

  /**
   * Deletes the draft state of the current component tree item.
   *
   * This method removes the draft state associated with the current component
   * tree item by using the draft key to identify the state to be deleted.
   *
   * @return self
   *   Returns the current instance of the component tree item.
   */
  public function deleteDraft(): self {
    $this->getState()->delete($this->getDraftKey());
    return $this;
  }

  /**
   * Checks if the current item has a draft version.
   *
   * This method first checks if the item belongs to a field configuration.
   * If it does, it returns FALSE, indicating that there is no draft.
   * Otherwise, it retrieves the draft value and returns it as a boolean.
   *
   * @return bool
   *   TRUE if the item has a draft version, FALSE otherwise.
   */
  public function hasDraft(): bool {
    if ($this->belongsToFieldConfig()) {
      return FALSE;
    }
    return (bool) $this->getDraftValue();
  }

  /**
   * Enforces the current item as a draft.
   *
   * @param bool $enforce
   *   (optional) Whether to enforce the item as a draft. Defaults to TRUE.
   *
   * @return self
   *   The current instance for chaining.
   */
  public function enforceAsDraft(bool $enforce = TRUE): self {
    $this->draft = $enforce;
    if ($enforce) {
      if ($draftValue = $this->getDraftValue()) {
        $list = $this->getParent();
        if ($list instanceof NeoComponentTreeList && $list->isHybridScope()) {
          // Normalize the stashed draft against the current field default
          // layout so structural changes made since the draft was stashed
          // (e.g. a header edit or a new footer) are reflected immediately.
          $draftValue = $list->composeHybridItemValue($draftValue);
        }
        $this->setValue($draftValue);
      }
    }
    return $this;
  }

  /**
   * Checks if the current item is a draft.
   *
   * @return bool
   *   TRUE if the item is a draft, FALSE otherwise.
   */
  public function isDraft(): bool {
    return $this->draft;
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($values, $notify = TRUE): void {
    // This field type does not want either:
    // - the parent FieldItemBase::setValue()'s behavior, which assigns $values
    //   to the first property if $values is not an array.
    // - the grandparent Map::setValue() removes key-value pairs from
    //   $this->values that are assigned to a n on-computed property.
    // Both of those behaviors prevent strict validation. Instead, perform *no*
    // magic transformations, just respect the `tree` and `props` key-value
    // pairs, if they are provided.
    if (is_array($values)) {
      // Store the exact values passed in to be assigned to the contained
      // properties.
      $this->values = $values;
      // Assign the values to the contained properties.
      if (array_key_exists('tree', $values)) {
        if (is_array($values['tree'])) {
          $values['tree'] = Json::encode($values['tree']);
        }
        $this->set('tree', $values['tree'], FALSE);
      }
      if (array_key_exists('props', $values)) {
        if (is_array($values['props'])) {
          $values['props'] = Json::encode($values['props']);
        }
        $this->set('props', $values['props'], FALSE);
      }
    }

    // If they are missing, fall back to the default value of the non-computed
    // properties `tree` and `props`. This avoids a *repeated* validation error:
    // if there already is a validation error for a missing key, another
    // validation error for an invalid value is not helpful.
    // @see \Drupal\experience_builder\Plugin\Validation\Constraint\ValidComponentTreeConstraintValidator
    foreach ($this->getProperties(FALSE) as $property_name => $property) {
      if (!is_array($values) || !array_key_exists($property_name, $values)) {
        $property->applyDefaultValue(FALSE);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(): void {
    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    $props = $this->get('props');
    assert($props instanceof ComponentPropsValues);

    // This *internal-only* validation does not need to happen using validation
    // constraints because it does not validate user input: it only helps ensure
    // that the logic of this field type is correct.
    $componentInstanceIds = $tree->getComponentInstanceUuids();
    if (array_intersect($componentInstanceIds, $props->getComponentInstanceUuids()) !== $componentInstanceIds) {
      throw new \LogicException(sprintf('The component UUIDs in the tree and props values do not match! Put a breakpoint here and figure out why.'));
    }
  }

  /**
   * Check if a component has props.
   *
   * @param string $component_id
   *   A Component config entity ID.
   *
   * @return bool
   *   TRUE if the component has props, FALSE otherwise.
   */
  protected static function componentHasProps(string $component_id): bool {
    $component_manager = \Drupal::service(ComponentPluginManager::class);
    $component = $component_manager->find($component_id);
    return !empty($component->metadata->schema['properties']);
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable(): array {
    $hydrated = $this->get('hydrated');
    assert($hydrated instanceof ComponentTreeHydrated);
    return $hydrated->toRenderable();
  }

  /**
   * Gets the state service.
   *
   * @return \Drupal\Core\State\StateInterface
   *   The state service.
   */
  protected function getState() {
    return \Drupal::state();
  }

  /**
   * {@inheritDoc}
   */
  public function setSizes(array $sizes): self {
    $settings['sizes'] = $sizes;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getSizes(): array {
    $settings = $this->getSettings();
    return $settings['sizes'] ?? [];
  }

  /**
   * {@inheritDoc}
   */
  public function allowSize(?string $size = NULL): bool {
    $sizes = $this->getSizes();
    if (empty($sizes)) {
      return TRUE;
    }
    return in_array($size, $sizes, TRUE);
  }

  /**
   * Gets the UUIDs of the entity-owned component instances (hybrid mode).
   *
   * Entity-owned instances are the components living inside the field's
   * entity-customizable regions, including nested descendants. Everything
   * else in the tree is inherited from the field default layout.
   *
   * @return string[]
   *   The entity-owned component instance UUIDs.
   */
  public function getEntityOwnedUuids(): array {
    $anchors = $this->getFieldDefinition()->getCustomRegions();
    if (!$anchors) {
      return [];
    }
    $tree = Json::decode($this->get('tree')->getValue() ?? '') ?: [];
    return NeoComponentTreeList::getSectionClosureUuids($tree, $anchors);
  }

  /**
   * Checks if an instance is inherited from the field default layout.
   *
   * Only meaningful in hybrid mode on an actual entity; every instance that
   * is not entity-owned is inherited.
   *
   * @param string $uuid
   *   The component instance UUID.
   *
   * @return bool
   *   TRUE when the instance is inherited, FALSE when entity-owned.
   */
  public function isInheritedInstance(string $uuid): bool {
    return !in_array($uuid, $this->getEntityOwnedUuids(), TRUE);
  }

  /**
   * Checks if a tree section may be customized per entity (hybrid mode).
   *
   * A section is customizable when it is a flagged custom region of the field
   * default layout, or any region inside an entity-owned component.
   *
   * @param string|null $parentUuid
   *   The parent component instance UUID, or NULL/root for the tree root.
   * @param string|null $slot
   *   The region shape ID within the parent.
   *
   * @return bool
   *   TRUE when the section is entity-customizable, FALSE otherwise.
   */
  public function isCustomTarget(?string $parentUuid, ?string $slot): bool {
    if (!$parentUuid || $parentUuid === ComponentTreeStructure::ROOT_UUID) {
      return FALSE;
    }
    $anchors = $this->getFieldDefinition()->getCustomRegions();
    if (isset($anchors[$parentUuid])) {
      return $slot && in_array($slot, $anchors[$parentUuid]['slots'], TRUE);
    }
    // Any region inside an entity-owned component is freely editable.
    return !$this->isInheritedInstance($parentUuid);
  }

  /**
   * Gets the size options.
   *
   * @return array
   *   An array of size options.
   */
  public static function getSizeOptions(): array {
    return array_map(
      fn ($size) => $size['label'],
      \Drupal::service('plugin.manager.neo_component_size')->getDefinitions()
    );
  }

}
