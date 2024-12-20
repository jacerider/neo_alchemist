<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Form\FormStateInterface;

/**
 * Base class for neo_component_value_provider plugins.
 */
abstract class ComponentValueProviderPluginBase extends ComponentValuePluginBase implements ComponentValueProviderPluginInterface {

  /**
   * Flag to continue processing.
   *
   * @var bool
   */
  protected $continueProcessing = TRUE;

  /**
   * {@inheritdoc}
   *
   * Creates a generic configuration form for all provider types. Individual
   * provider plugins can add elements to this form by overriding
   * ComponentValuePluginProviderBase::providerForm(). Most provider plugins
   * should not override this method unless they need to alter the generic form
   * elements.
   *
   * @see \Drupal\neo_alchemist\ComponentValuePluginProviderBase::providerForm()
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, array &$complete_form = NULL) {
    $form += $this->providerForm($form, $form_state, $complete_form);
    return $form;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function providerForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Most provider plugins should not override this method. To add validation
   * for specific provider type, override
   * ComponentValuePluginProviderBase::providerValidate().
   *
   * @see \Drupal\neo_alchemist\ComponentValuePluginProviderBase::providerValidate()
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->providerValidate($form, $form_state);
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function providerValidate(array $form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function allowProcessing(string $op): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function allowFurtherProcessing(): self {
    $this->continueProcessing = TRUE;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function stopFurtherProcessing(): self {
    $this->continueProcessing = FALSE;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function shouldContinueProcessing(): bool {
    return $this->continueProcessing;
  }

  /**
   * {@inheritdoc}
   */
  public function onShapeInit() {
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function provideOverrideValue(mixed $value): mixed {
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function formAlter(array &$element, FormStateInterface $form_state) {
  }

}
