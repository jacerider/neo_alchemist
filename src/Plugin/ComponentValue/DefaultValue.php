<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginManager;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'default',
  label: new TranslatableMarkup('Default'),
  description: new TranslatableMarkup('Provide default values for the component.'),
  // Not a provider: this is the terminal fallback (weight 1000). It never
  // sources a value, it only fills one in when nothing else did.
  group: 'fallback',
  weight: 1000,
)]
final class DefaultValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The default shape plugin.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface
   */
  protected ComponentShapePluginInterface $defaultShape;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['shape'],
      $configuration['settings'],
      $container->get('plugin.manager.neo_component_shape'),
    );
  }

  /**
   * Creates a toolbar item instance.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    protected ComponentShapePluginManager $shapeManager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'field_type' => NULL,
      'default' => NULL,
      'options' => [],
    ];
  }

  /**
   * Retrieves the default shape for the component.
   *
   * @return \Drupal\neo_alchemist\Plugin\ComponentShapePluginInterface
   *   The default shape for the component.
   */
  protected function getDefaultShape(): ComponentShapePluginInterface {
    if (!isset($this->defaultShape)) {
      $this->defaultShape = $this->shapeManager->getInstance([
        'schema' => $this->shape->getSchema(),
        'component' => $this->shape->getComponent(),
        'settings' => $this->shape->getSettings(),
      ]);

      $valueCollection = $this->defaultShape->getValueCollection();

      // Never allow the default shape to have the default plugin enabled.
      $valueCollection->setStatus('default', FALSE);

      // Only allow plugins that are flagged to be allowed on the default shape.
      foreach ($valueCollection->getActiveInstances() as $pluginId => $plugin) {
        $valueCollection->setStatus($pluginId, $plugin->allowOnDefault());
      }

      // Temporary fix for options structure.
      $options = $this->configuration['options'];
      if (isset($options['empty']) || isset($options['default'])) {
        $this->configuration['options'] = [
          $this->defaultShape->id() => $options,
        ];
      }

      $this->defaultShape
        ->setParentValue($this->configuration['default'] ?? $this->shape->buildDefaultValue())
        ->setExpanded($this->shape->getExpanded());
      foreach ($this->shape->getParentShapes() as $parentShape) {
        $this->defaultShape->addParentShape($parentShape);
      }
      $this->defaultShape->setDefaultNestedOptions($this->configuration['options'] ?? []);
      $this->defaultShape->init();
      $this->defaultShape->getOptionDefault()->alwaysShowForm(TRUE, 'Always show form when default.');
      if (!$this->defaultShape->isIterable()) {
        $this->defaultShape->getOptionEmpty()->alwaysShowForm(TRUE, 'Always show form when default.');
      }
    }
    return $this->defaultShape;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form_state->set('neo_component_form', TRUE);
    $defaultShape = $this->getDefaultShape();
    $form = $defaultShape->getForm($form, $form_state);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationMassage(array $values, array $form, FormStateInterface $form_state): array {
    $defaultShape = $this->getDefaultShape();
    $values = $values[$defaultShape->getName()] ?? [];
    // @todo Restore the "just enabled, no widget" early return. It was removed
    // because object and array props have no widget and must still pass values.
    if (!empty(Element::children($form))) {
      $defaultShape->validateForm($form, $form_state, $values);
      $originalValues = $this->configuration['default'] ?? [];
      if (!is_array($originalValues)) {
        $originalValues = [$originalValues];
      }
      $nestedOptions = array_filter($defaultShape->getNestedOptions(), function ($key) use ($defaultShape) {
        $id = $defaultShape->id();
        if ($key === $id) {
          return TRUE;
        }
        if (substr($key, 0, strlen($id) + 1) === $id . '~') {
          return TRUE;
        }
        return $key === $defaultShape->id();
      }, ARRAY_FILTER_USE_KEY);
      $values = [
        'field_type' => $defaultShape->getFieldType(),
        'default' => $defaultShape->massageFormValues($values, $originalValues, $form, $form_state),
        'options' => $nestedOptions,
      ];
      if ($values['default'] === '_default') {
        $values['default'] = NULL;
      }
    }
    else {
      $values = [
        'field_type' => NULL,
        'default' => [],
        'options' => [],
      ];
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function onShapeInit() {
    parent::onShapeInit();
    $this->shape->setDefaultNestedOptions($this->configuration['options'] ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    // Fallback only: preserve any value an earlier (non-claiming) provider
    // supplied, and never return NULL over a threaded value. This provider is
    // terminal (weight 1000) so it does not claim.
    //
    // The incoming value seeds from the shape's schema example — the component
    // author's placeholder. A site-builder's configured default is meant to
    // supersede that placeholder, so treat the untouched example the same as an
    // empty value: only a genuine provider value (one that differs from the
    // example) is preserved.
    $isUntouchedExample = $value === $this->shape->resolveValue($this->shape->getDefaultSchemaValue());
    if (!$isUntouchedExample && !$this->shape->isProvidedValueEmpty($value)) {
      return $value;
    }
    return $this->configuration['default'] ?? $value;
  }

}
