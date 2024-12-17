<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValueProvider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValueProvider;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValueProviderPluginBase;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValueProvider(
  id: 'default',
  label: new TranslatableMarkup('Default'),
  description: new TranslatableMarkup('Provide default values for the component.'),
  weight: 15,
)]
final class DefaultValueProvider extends ComponentValueProviderPluginBase {

  /**
   * The default shape plugin.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface
   */
  protected ComponentShapePluginInterface $defaultShape;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'default' => $this->shape->getDefaultValue(),
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
      $this->defaultShape = $this->shape->getDefaultShape()->setFieldItemValue($this->configuration['default']);
    }
    return $this->defaultShape;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function providerForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $defaultShape = $this->getDefaultShape();
    $form = $defaultShape->getForm($form, $form_state);
    return $form;
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function providerValidate(array $form, FormStateInterface $form_state): void {
    $defaultShape = $this->getDefaultShape();
    $values = $form_state->getValues()[$defaultShape->getName()] ?? [];
    $defaultShape->validateForm($form, $form_state, $values);
    $originalValues = $this->configuration['default'];
    if (!is_array($originalValues)) {
      $originalValues = [$originalValues];
    }
    $values = $defaultShape->massageFormValues($values, $originalValues, $form, $form_state);
    $form_state->setValues([
      'default' => $values,
      'options' => $form_state->getValue('_options'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function onShapeInit() {
    $this->shape->setOptions($this->configuration['options'] ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    return $this->configuration['default'];
  }

}
