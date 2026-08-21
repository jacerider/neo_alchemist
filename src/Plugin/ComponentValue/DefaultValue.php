<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginManager;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProvision;
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
   * The default shape plugin, initialised.
   *
   * Only assigned from ::init()'s return, so the shape under construction
   * never outlives ::getDefaultShape() — it is a local there. Holding the
   * setup type on a property would keep the setters reachable for the life of
   * this plugin, which is the seam the interface exists to close.
   *
   * @var \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
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
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $default = $this->configuration['default'] ?? NULL;
    $flat = [];
    // array_walk_recursive() takes its subject by reference, so it must be a
    // variable — an inline expression is a runtime fatal.
    $walkable = is_array($default) ? $default : [$default];
    array_walk_recursive($walkable, static function ($leaf) use (&$flat) {
      if (is_scalar($leaf) && trim((string) $leaf) !== '') {
        $flat[] = (string) $leaf;
      }
    });
    if (!$flat) {
      return [];
    }
    return [
      $this->t('Default: %value', [
        '%value' => Unicode::truncate(implode(', ', $flat), 60, TRUE, TRUE),
      ]),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Declares the wider handle: DefaultValue reads the schema (Schema), walks
   * the parent shapes (Tree), reads whether the prop is expanded (Expansion)
   * and merges the nested option map (Options) — all beyond the producer
   * handle. The union is a subtype of the handle, so this override narrows the
   * return type covariantly; the shape held at runtime is always the union.
   */
  public function getShape(): ComponentShapePluginInterface {
    assert($this->shape instanceof ComponentShapePluginInterface);
    return $this->shape;
  }

  /**
   * Retrieves the default shape for the component.
   *
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
   *   The default shape for the component, initialised.
   */
  protected function getDefaultShape(): ComponentShapePluginInterface {
    if (!isset($this->defaultShape)) {
      // A shape under construction, held locally rather than on the property:
      // what init() hands back is what this plugin keeps.
      $shape = $this->shapeManager->getInstance([
        'schema' => $this->getShape()->getSchema(),
        'component' => $this->shape->getComponent(),
        'settings' => $this->shape->getSettings(),
      ]);

      $valueCollection = $shape->getValueCollection();

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
          $shape->id() => $options,
        ];
      }

      $shape
        ->setParentValue($this->configuration['default'] ?? $this->shape->buildDefaultValue())
        ->setExpanded($this->getShape()->getExpanded());
      foreach ($this->getShape()->getParentShapes() as $parentShape) {
        $shape->addParentShape($parentShape);
      }
      $shape->getNestedOptionMap()->mergeFallbacks($this->configuration['options'] ?? []);

      $this->defaultShape = $shape->init();
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
      $values = [
        'field_type' => $defaultShape->getFieldType(),
        'default' => $defaultShape->massageFormValues($values, $originalValues, $form, $form_state),
        // This provider stores its own shape's options, not the whole
        // component's. Narrowing used to be done here with a hand-rolled copy
        // of the shape-id key format.
        'options' => $defaultShape->getNestedOptionMap()->subtree(),
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
    $this->getShape()->getNestedOptionMap()->mergeFallbacks($this->configuration['options'] ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function provide(mixed $value): ComponentValueProvision {
    // Fallback only: preserve any value an earlier (non-claiming) provider
    // supplied, and never return NULL over a threaded value.
    //
    // The incoming value seeds from the shape's schema example — the component
    // author's placeholder. A site-builder's configured default is meant to
    // supersede that placeholder, so treat the untouched example the same as an
    // empty value: only a genuine provider value (one that differs from the
    // example) is preserved.
    $isUntouchedExample = $value === $this->shape->resolveValue($this->shape->getDefaultSchemaValue());
    if (!$isUntouchedExample && !$this->shape->isProvidedValueEmpty($value)) {
      return ComponentValueProvision::offer($value);
    }

    // NULL is the "no default configured" sentinel — ::configurationMassage()
    // maps the widget's `_default` choice onto it — so leave the example alone.
    $default = $this->configuration['default'] ?? NULL;
    if ($default === NULL) {
      return ComponentValueProvision::offer($value);
    }

    // Anything else is an answer the site-builder typed, and emptying the
    // widget is one of the answers they can type. Claim it: the search
    // discards an empty value from a provider that has not claimed, on the
    // grounds that a producer which found nothing should not destroy what was
    // threaded past it — but this plugin is not a producer looking something
    // up, it is the configured default itself, so its empty means "no default"
    // and not "I came up short". Without the claim, clearing every item out of
    // a default put the component author's example back on screen, which reads
    // as the removal never having saved. At weight 1000 there is nothing after
    // this anyway; the claim is what keeps the empty default standing instead
    // of falling through to the example.
    return ComponentValueProvision::claim($default);
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    return $this->provide($value)->getValue();
  }

}
