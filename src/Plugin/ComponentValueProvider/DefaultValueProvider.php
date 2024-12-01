<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValueProvider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValueProvider;
use Drupal\neo_alchemist\ComponentValueProviderPluginBase;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValueProvider(
  id: 'default',
  label: new TranslatableMarkup('Default'),
  description: new TranslatableMarkup('Provide default values for the component.'),
  weight: 10,
)]
final class DefaultValueProvider extends ComponentValueProviderPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'default' => $this->shape->getDefaultValue(),
    ];
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function providerForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $this->shape->setFieldItemValue($this->configuration['default']);
    $form = $this->shape->getForm($form, $form_state);
    return $form;
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function providerValidate(array $form, FormStateInterface $form_state): void {
    $values = $form_state->getValues()[$this->shape->getName()] ?? [];
    $this->shape->validateForm($form, $form_state, $values);
  }

  /**
   * Form submit for the value provider plugin configuration.
   */
  protected function providerSubmit(array $form, FormStateInterface $form_state): void {
    $values = $form_state->getValues()[$this->shape->getName()] ?? [];
    $value = $this->shape->massageFormValues($form, $form_state, $values);
    $form_state->setValues(['default' => $value]);
  }

  /**
   * {@inheritdoc}
   */
  public function onCalculateFieldItemValue() {
    $this->shape->setFieldItemValue($this->configuration['default']);
  }

  /**
   * {@inheritdoc}
   */
  public function fieldItemValue(): array {
    return $this->configuration['default'];
  }

}
