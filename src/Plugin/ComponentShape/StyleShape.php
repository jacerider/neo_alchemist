<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentShape;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Attribute\ComponentShape;
use Drupal\neo_alchemist\ComponentShapeStyleAttribute;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\WidgetPluginManager;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentValuePluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_shape.
 */
#[ComponentShape(
  prop: 'style',
  label: new TranslatableMarkup('Style'),
  default_field_type: 'list_string',
  default_field_widget: 'options_select',
)]
class StyleShape extends StyleShapeBase {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $config;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    array $schema,
    array $settings,
    protected ComponentInterface $component,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TypedDataManagerInterface $typedDataManager,
    protected WidgetPluginManager $widgetManager,
    protected ComponentValuePluginManagerInterface $valueManager,
    protected ConfigFactoryInterface $config_factory,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $schema, $settings, $component, $entityTypeManager, $typedDataManager, $widgetManager, $valueManager);
    $this->config = $this->config_factory->get('neo_alchemist.style_settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['schema'],
      $configuration['settings'],
      $configuration['component'],
      $container->get('entity_type.manager'),
      $container->get(TypedDataManagerInterface::class),
      $container->get('plugin.manager.field.widget'),
      $container->get('plugin.manager.neo_component_value'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritDoc}
   */
  public function getFieldOptions(): ?array {
    if (array_key_exists('styles', $this->schema)) {
      $values = array_map(function ($style) {
        return $style['label'] ?? 'Unnamed';
      }, $this->schema['styles']);
      $settings = $this->config->get('styles.' . $this->getName());
      if ($settings) {
        $mode = $settings['mode'] ?? 'exclude';
        $selected = array_filter($settings['values'] ?? []);
        if ($selected) {
          if ($mode === 'include') {
            $values = array_intersect_key($values, array_flip($selected));
          }
          elseif ($mode === 'exclude') {
            $values = array_diff_key($values, array_flip($selected));
          }
        }
      }
      return $values;
    }
    return [];
  }

  /**
   * {@inheritDoc}
   */
  protected function formWidgetAlter(array &$form, FormStateInterface $form_state): void {
    if (isset($form['widget']['#options']['_none'])) {
      $form['widget']['#options']['_none'] = $this->isRequired() ? $this->t('- Default -') : $this->t('- None -');
    }
  }

  /**
   * {@inheritDoc}
   */
  protected function preRenderValue(mixed $value, Attribute $attributes): mixed {
    $finalValue = new ComponentShapeStyleAttribute([], $value ?: NULL);
    if ($value && isset($this->schema['styles'][$value]['value'])) {
      $finalValue->addClass($this->schema['styles'][$value]['value']);
    }
    if (array_key_exists('apply', $this->schema) && !empty($this->schema['apply'])) {
      $attributes->merge($finalValue);
    }
    return $finalValue;
  }

}
