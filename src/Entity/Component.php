<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\Plugin\Component as ComponentPlugin;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\PropExpressions\Component\ComponentPropExpression;
use Drupal\neo_alchemist\PropExpressions\StructuredData\FieldTypeObjectPropsExpression;
use Drupal\neo_alchemist\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\neo_alchemist\PropShape\PropShape;
use Drupal\neo_alchemist\PropSource\StaticPropSource;

/**
 * Defines the component entity type.
 *
 * @ConfigEntityType(
 *   id = "neo_component",
 *   label = @Translation("Component"),
 *   label_collection = @Translation("Components"),
 *   label_singular = @Translation("component"),
 *   label_plural = @Translation("components"),
 *   label_count = @PluralTranslation(
 *     singular = "@count component",
 *     plural = "@count components",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\neo_alchemist\ComponentListBuilder",
 *     "form" = {
 *       "add" = "Drupal\neo_alchemist\Form\ComponentForm",
 *       "edit" = "Drupal\neo_alchemist\Form\ComponentForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *       "manage" = "Drupal\neo_alchemist\Form\ComponentManageForm",
 *     },
 *   },
 *   config_prefix = "neo_component",
 *   admin_permission = "administer neo_alchemist",
 *   links = {
 *     "collection" = "/admin/config/neo/alchemist",
 *     "add-form" = "/admin/config/neo/alchemist/add/{component}",
 *     "edit-form" = "/admin/config/neo/alchemist/{neo_component}/edit",
 *     "delete-form" = "/admin/config/neo/alchemist/{neo_component}/delete",
 *     "canonical" = "/admin/config/neo/alchemist/{neo_component}",
 *     "preview" = "/admin/config/neo/alchemist/{neo_component}/preview",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "component" = "component",
 *     "label" = "label",
 *     "status" = "status",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "component",
 *     "defaults",
 *     "overrides",
 *     "target_entity_type",
 *     "target_entity_bundle",
 *   },
 * )
 */
final class Component extends ConfigEntityBase implements ComponentInterface {

  /**
   * The example ID.
   */
  protected string $id;

  /**
   * The example label.
   */
  protected string $label;

  /**
   * The example description.
   */
  protected string $description;

  /**
   * The SDS component.
   */
  protected string $component;

  /**
   * The defaults.
   *
   * @var array
   */
  protected ?array $defaults;

  /**
   * The overrides.
   *
   * @var array
   */
  protected ?array $overrides;

  /**
   * The target entity type.
   */
  protected ?string $target_entity_type = '';

  /**
   * The target entity bundle.
   */
  protected ?string $target_entity_bundle = '';

  /**
   * {@inheritdoc}
   */
  public function getComponentId(): string {
    return $this->component;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponent(): ComponentPlugin {
    /** @var \Drupal\Core\Theme\ComponentPluginManager $manager */
    $manager = \Drupal::service('plugin.manager.sdc');
    return $manager->find($this->getComponentId());
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentSchema(): mixed {
    $component = $this->getComponent();
    // Get the component based on the ID, so we can get the schema.
    $prop_schema = $component->metadata->schema;
    // Encode & decode, so we transform an associative array to an stdClass
    // recursively.
    try {
      $schema = json_decode(
        json_encode($prop_schema, JSON_THROW_ON_ERROR),
        FALSE,
        512,
        JSON_THROW_ON_ERROR
      );
    }
    catch (\JsonException $e) {
      $schema = (object) [];
    }

    return $schema;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponentDefinition(): array {
    /** @var \Drupal\Core\Theme\ComponentPluginManager $manager */
    $manager = \Drupal::service('plugin.manager.sdc');
    return $manager->getDefinition($this->getComponentId());
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityTypeId(): string {
    return $this->get('target_entity_type');
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityTypeDefinition(): EntityTypeInterface|null {
    $targetEntityType = $this->getTargetEntityTypeId();
    return $targetEntityType ? \Drupal::entityTypeManager()->getDefinition($targetEntityType) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getTargetEntityBundle(): string {
    return $this->get('target_entity_bundle');
  }

  /**
   * {@inheritdoc}
   */
  public function save() {
    if ($this->isNew()) {
      $this->set('id', $this->getUniqueId());
    }
    $component_plugin = $this->getComponent();
    assert(is_array($component_plugin->metadata->schema));
    $defaults = self::getDefaultsForComponentPlugin($component_plugin);
    foreach ($defaults['props'] as $prop_name => &$prop) {
      if (isset($this->overrides['props'][$prop_name]['default_value']) && !empty($this->overrides['props'][$prop_name]['default_value'])) {
        $values = $this->overrides['props'][$prop_name]['default_value'];
        $source = $this->getDefaultStaticPropSource($prop_name);
        $source->fieldItem->setValue($values);
        $values = $source->evaluate(NULL);
        $prop['default_value'] = $source->withValue($values)->fieldItem->getValue();
        // ksm($prop, $values, $source->withValue($values)->fieldItem->getValue());
        // $defaults['props'][$prop_name] = array_merge($defaults['props'][$prop_name], $this->defaults['props'][$prop_name]);
      }
    }
    $this->set('defaults', $defaults);
    return parent::save();
  }

  /**
   * Generates a unique machine name for a component.
   *
   * @return string
   *   Returns the unique name.
   */
  public function getUniqueId() {
    $suggestion = str_replace(':', '+', $this->getComponentId());

    // Get all the blocks which starts with the suggested machine name.
    $query = $this->entityTypeManager()->getStorage('neo_component')->getQuery();
    $query->condition('id', $suggestion, 'CONTAINS');
    $item_ids = $query->accessCheck(FALSE)->execute();

    $item_ids = array_map(function ($item_id) {
      $parts = explode('.', $item_id);
      return end($parts);
    }, $item_ids);

    // Iterate through potential IDs until we get a new one. E.g.
    // 'plugin', 'plugin_2', 'plugin_3', etc.
    $count = 1;
    $machine_default = $suggestion;
    while (in_array($machine_default, $item_ids)) {
      $machine_default = $suggestion . '+' . ++$count;
    }
    return $machine_default;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();

    $provider = explode(':', $this->getComponentId())[0];

    if ($this->moduleHandler()->moduleExists($provider)) {
      $this->addDependency('module', $provider);
    }
    elseif ($this->themeHandler()->themeExists($provider)) {
      $this->addDependency('theme', $provider);
    }

    // Taken from Experience Builder.
    $field_type_plugin_manager = \Drupal::service(FieldTypePluginManagerInterface::class);
    assert($field_type_plugin_manager instanceof FieldTypePluginManagerInterface);
    $field_widget_plugin_manager = \Drupal::service('plugin.manager.field.widget');
    assert($field_widget_plugin_manager instanceof WidgetPluginManager);
    assert(is_array($this->defaults));
    foreach ($this->defaults['props'] ?? [] as [
      'field_type' => $field_type,
      'field_widget' => $field_widget,
    ]) {
      // TRICKY: `field_type` (and `field_widget`) may not be set if no field
      // types match this SDC prop shape.
      if ($field_type === NULL) {
        continue;
      }
      $field_type_definition = $field_type_plugin_manager->getDefinition($field_type);
      $this->addDependency('module', $field_type_definition['provider']);
      $field_widget_definition = $field_widget_plugin_manager->getDefinition($field_widget);
      $this->addDependency('module', $field_widget_definition['provider']);
    }
    // End Experience Builder.
    if ($definition = $this->getTargetEntityTypeDefinition()) {
      $this->addDependency('module', $definition->getProvider());
      $bundle = $this->getTargetEntityBundle();
      if ($bundle = $this->getTargetEntityBundle()) {
        if ($bundleDependency = $definition->getBundleConfigDependency($bundle)) {
          $this->addDependency($bundleDependency['type'], $bundleDependency['name']);
        }
      }
    }

    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * Taken directly from Experience Builder.
   */
  public function getDefaultStaticPropSource(string $prop_name): ?StaticPropSource {
    assert(is_array($this->defaults));

    $component = $this->getComponent();
    assert($component instanceof ComponentPlugin);
    if (!array_key_exists($prop_name, $component->metadata->schema['properties'] ?? [])) {
      throw new \OutOfRangeException(sprintf("'%s' is not a prop on the '%s' component.", $prop_name, $this->getComponentId()));
    }

    $value = $this->defaults['props'][$prop_name]['default_value'];
    if (isset($this->overrides['props'][$prop_name]['default_value']) && $this->overrides['props'][$prop_name]['expression'] === $this->defaults['props'][$prop_name]['expression']) {
      $value = $this->overrides['props'][$prop_name]['default_value'] ?: $value;
    }

    $sdc_prop_source = [
      'sourceType' => 'static:field_item:' . $this->defaults['props'][$prop_name]['field_type'],
      'value' => $value,
      'expression' => $this->defaults['props'][$prop_name]['expression'],
    ];
    if (array_key_exists('field_storage_settings', $this->defaults['props'][$prop_name])) {
      $sdc_prop_source['sourceTypeSettings']['storage'] = $this->defaults['props'][$prop_name]['field_storage_settings'];
    }
    if (array_key_exists('field_instance_settings', $this->defaults['props'][$prop_name])) {
      $sdc_prop_source['sourceTypeSettings']['instance'] = $this->defaults['props'][$prop_name]['field_instance_settings'];
    }

    return StaticPropSource::parse($sdc_prop_source);
  }

  public function getDefaultStaticPropValues() {
    $component = $this->getComponent();
    $props = [];
    foreach (PropShape::getComponentProps($component) as $component_prop_expression => $prop_shape) {
      $storable_prop_shape = $prop_shape->getStorage();
      // @todo Remove this once every SDC prop shape can be stored.
      // @todo Create a status report that lists which SDC props are not storable.
      if (!$storable_prop_shape) {
        continue;
      }
      $component_prop = ComponentPropExpression::fromString((string) $component_prop_expression);
      $prop_info = ($component->metadata->schema['properties'] ?? [])[$component_prop->propName];
      $default_static_source = $this->getDefaultStaticPropSource($component_prop->propName);
      $default_value = $prop_info['examples'][0];
      if ($default_static_source->fieldItem->getValue()) {
        $default_value = $default_static_source?->evaluate(NULL);
      }
      $props[$component_prop->propName] = $default_value;
    }
    return $props;
  }

  public function toRenderable() {
    return [
      '#type' => 'component',
      '#component' => $this->getComponent()->getPluginId(),
      '#props' => $this->getDefaultStaticPropValues(),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Taken directly from Experience Builder.
   */
  public static function getDefaultsForComponentPlugin(ComponentPlugin $component_plugin): array {
    $defaults = ['props' => []];
    $weight = 0;
    foreach (PropShape::getComponentProps($component_plugin) as $cpe_string => $prop_shape) {
      $cpe = ComponentPropExpression::fromString((string) $cpe_string);

      assert(is_array($component_plugin->metadata->schema));
      // @see https://json-schema.org/understanding-json-schema/reference/object#required
      // @see https://json-schema.org/learn/getting-started-step-by-step#required
      $is_required = in_array($cpe->propName, $component_plugin->metadata->schema['required'] ?? [], TRUE);

      $skip_prop = FALSE;
      $storable_prop_shape = $prop_shape->getStorage();
      if (is_null($storable_prop_shape)) {
        continue;
      }

      if ($storable_prop_shape->fieldTypeProp instanceof FieldTypeObjectPropsExpression) {
        // @todo Add support for default images: /components/image/image.component.yml.
        if ($storable_prop_shape->fieldTypeProp->fieldType === 'entity_reference') {
          $skip_prop = TRUE;
        }
        else {
          foreach ($storable_prop_shape->fieldTypeProp->objectPropsToFieldTypeProps as $field_type_prop) {
            if ($field_type_prop instanceof ReferenceFieldTypePropExpression) {
              $skip_prop = TRUE;
            }
          }
        }
      }
      $static_prop_source = $storable_prop_shape->toStaticPropSource();

      // @see `type: experience_builder.component.*`
      assert(array_key_exists('properties', $component_plugin->metadata->schema));
      $defaults['props'][$cpe->propName] = [
        'field_type' => $storable_prop_shape->fieldTypeProp->fieldType,
        'field_widget' => $storable_prop_shape->fieldWidget,
        'expression' => (string) $storable_prop_shape->fieldTypeProp,
        // TRICKY: need to transform to the array structure that depends on the
        // field type.
        // @see `type: field.storage_settings.*`
        'default_value' => $skip_prop ? [] : $static_prop_source->withValue(
          $is_required
            // Example guaranteed to exist if a required prop.
            ? $component_plugin->metadata->schema['properties'][$cpe->propName]['examples'][0]
            // Example may exist if an optional prop.
            : (
              array_key_exists('examples', $component_plugin->metadata->schema['properties'][$cpe->propName]) && array_key_exists(0, $component_plugin->metadata->schema['properties'][$cpe->propName]['examples'])
                ? $component_plugin->metadata->schema['properties'][$cpe->propName]['examples'][0]
                : NULL
            )
        )->fieldItem->getValue(),
        'field_storage_settings' => $storable_prop_shape->fieldStorageSettings ?? [],
        'field_instance_settings' => $storable_prop_shape->fieldInstanceSettings ?? [],
        'weight' => $weight++,
      ];
    }

    return $defaults;
  }

}
