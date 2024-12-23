<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapeChildrenPluginInterface;
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
  group: 'providers',
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
    protected ComponentShapePluginManager $shapeManager
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'default' => $this->getDefaultShape()->getDefaultValue(),
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
      ]);
      $this->defaultShape->getOptionDefault()->setAccess(FALSE);
      $this->defaultShape
        ->setNestedOptions($this->configuration['options'] ?? [])
        ->setOverrideValue($this->configuration['default'] ?? [])
        // Send expanded status.
        ->setExpanded($this->shape->getExpanded())
        ->init();
    }
    return $this->defaultShape;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $defaultShape = $this->getDefaultShape();
    $form = $defaultShape->getForm($form, $form_state);
    return $form;
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $defaultShape = $this->getDefaultShape();
    $values = $form_state->getValues()[$defaultShape->getName()] ?? [];
    $defaultShape->validateForm($form, $form_state, $values);
    $originalValues = $this->configuration['default'];
    if (!is_array($originalValues)) {
      $originalValues = [$originalValues];
    }
    $values = [
      'default' => $defaultShape->massageFormValues($values, $originalValues, $form, $form_state),
      // 'options' => $form_state->getValue('_options') ?? [],
      'options' => $defaultShape->getNestedOptions(),
    ];
    $form_state->setValues(array_filter($values));
  }

  /**
   * {@inheritdoc}
   */
  public function onShapeInit() {
    parent::onShapeInit();
    $this->shape->setNestedOptions($this->configuration['options'] ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    return $this->configuration['default'];
  }

}
