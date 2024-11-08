<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\Field\FieldType;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Render\RenderableInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\Url;
use Drupal\neo_alchemist\ComponentInstanceInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Entity\Component;
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
class ComponentTreeItem extends FieldItemBase implements RenderableInterface {

  /**
   * The data definition.
   *
   * @var \Drupal\Core\Field\TypedData\FieldItemDataDefinition
   */
  protected $definition;

  /**
   * The Neo component.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface[]
   */
  protected static array $components = [];

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings() {
    return [
      'defaults' => [],
    ];
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
    return match($rel) {
      'library' => $this->getEntity()->toUrl("alchemist.{$fieldName}.library"),
      'add' => $this->getEntity()->toUrl("alchemist.{$fieldName}.add"),
      'reset' => $this->getEntity()->toUrl("alchemist.{$fieldName}.reset"),
      'sort' => $this->getEntity()->toUrl("alchemist.{$fieldName}.sort"),
      default => $this->getEntity()->toUrl("alchemist.{$fieldName}")
    };
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
    return $tree === '' || $tree === Json::encode([]) || $tree === Json::encode([ComponentTreeStructure::ROOT_UUID => []]);
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
   * @param string $parentUuid
   *   The UUID of the parent component. Defaults to the root UUID.
   *
   * @return array
   *   An associative array where the keys are component UUIDs and the values
   *   are component labels.
   */
  public function toOptions(string $parentUuid = ComponentTreeStructure::ROOT_UUID) {
    $options = [];
    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    foreach ($tree->getComponentBySection($parentUuid) as $key => $data) {
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
   * Currently, we just check if the associated content entity is new. If it is,
   * we know it was dynamically created and is therefore not attached to a real
   * entity. We therefore assume it belongs to a field config and should be
   * treated as such.
   *
   * @return bool
   *   TRUE if the item belongs to an actual entity, FALSE otherwise.
   */
  public function belongsToFieldConfig(): bool {
    return $this->getParent()->belongsToFieldConfig();
    // return $this->getEntity()->isNew();
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
   *
   * @return \Drupal\neo_alchemist\ComponentInstanceInterface
   *   The component instance.
   */
  public function createComponent(ComponentInterface $neoComponent): ComponentInstanceInterface {
    $value = $neoComponent->toArray();
    // Clear UUID so that a new one will be generated on creation.
    $value['uuid'] = NULL;
    $value['fieldItem'] = $this;
    $entity_class = $this->getComponentInstanceClass();
    $instance = new $entity_class($value, 'neo_component');
    $instance->enforceIsNew();
    return $instance;
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
    if (!isset(self::$components[$uuid])) {
      self::$components[$uuid] = NULL;
      $tree = $this->get('tree');
      assert($tree instanceof ComponentTreeStructure);
      $props = $this->get('props');
      assert($props instanceof ComponentPropsValues);
      $id = $tree->getComponentId($uuid);
      if ($id) {
        $neoComponent = clone Component::load($id);
        $value = $neoComponent->toArray();
        $value['uuid'] = $uuid;
        $value['fieldItem'] = $this;
        $value['values'] = $props->getComponentPropsSources($uuid);
        $entity_class = $this->getComponentInstanceClass();
        $instance = new $entity_class($value, 'neo_component');
        self::$components[$uuid] = $instance;
      }
    }
    return self::$components[$uuid];
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
    foreach ($tree->getComponentBySection($parentUuid) as $data) {
      $components[$data['uuid']] = $this->getComponent($data['uuid']);
    }
    return $components;
  }

  public function resetComponents(): self {
    $this->parent->setValue(NULL);
    // $this->setValue(NULL);
    // $this->get('tree')->setValue('{}');
    // $this->get('props')->setValue('{}');
    // $tree = $this->get('tree');
    // assert($tree instanceof ComponentTreeStructure);
    // $props = $this->get('props');
    // assert($props instanceof ComponentPropsValues);
    // $tree->reset();
    // $props->reset();
    return $this;
  }

  /**
   * Sorts the components within the tree structure.
   *
   * @param array $component_instance_uuids
   *   An array of component instance UUIDs to be sorted.
   * @param string $parentUuid
   *   (optional) The UUID of the parent component. Defaults to the root UUID.
   * @param mixed $slot
   *   (optional) The slot within the parent component where the components
   *   should be sorted.
   */
  public function sortComponents(array $component_instance_uuids, string $parentUuid = ComponentTreeStructure::ROOT_UUID, $slot = NULL) {
    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);
    $tree->sortComponents($component_instance_uuids, $parentUuid, $slot);
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
    $tree->removeComponent($uuid);
    $props->removeComponent($uuid);
    return $this;
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
    $component_instance_uuids = $tree->getComponentInstanceUuids();
    if (array_intersect($component_instance_uuids, $props->getComponentInstanceUuids()) !== $component_instance_uuids) {
      throw new \LogicException(sprintf('The component UUIDs in the tree and props values do not match! Put a breakpoint here and figure out why.'));
    }
  }

  /**
   * Resolve the props values for a component instance.
   *
   * @param string $component_instance_uuid
   *   The UUID of a placed component instance.
   *
   * @return array
   *   The props values for the component instance.
   */
  public function resolveComponentProps(string $component_instance_uuid): array {
    $props = $this->get('props');
    assert($props instanceof ComponentPropsValues);
    $tree = $this->get('tree');
    assert($tree instanceof ComponentTreeStructure);

    $neoComponent = $this->getComponent($component_instance_uuid);
    if (!$neoComponent) {
      return [];
    }
    if (!self::componentHasProps($neoComponent->getComponentId())) {
      return [];
    }
    return $neoComponent->getPropValues();
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

}
