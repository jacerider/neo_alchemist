<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginWithFormsTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Base class for neo_component_slot plugins.
 */
abstract class ComponentFilterPluginBase extends PluginBase implements ComponentFilterPluginInterface {

  use PluginWithFormsTrait;
  use StringTranslationTrait;

  /**
   * The filter.
   *
   * @var \Drupal\neo_alchemist\ComponentFilterInterface
   */
  protected ComponentFilterInterface $filter;

  /**
   * The filter value.
   *
   * @var mixed
   */
  protected mixed $value;

  /**
   * Creates a toolbar item instance.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentFilterInterface $filter,
    array $configuration,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->filter = $filter;
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = NestedArray::mergeDeep(
      $this->baseConfigurationDefaults(),
      $this->defaultConfiguration(),
      $configuration
    );
  }

  /**
   * Returns generic default configuration for modifier plugins.
   *
   * @return array
   *   An associative array with the default configuration.
   */
  protected function baseConfigurationDefaults() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    // Cast the label to a string since it is a TranslatableMarkup object.
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function valueSummary(?string $value): ?string {
    return NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Creates a generic configuration form for all provider types. Individual
   * provider plugins can add elements to this form by overriding
   * ComponentValuePluginProviderBase::configurationForm(). Most provider
   * plugins should not override this method unless they need to alter the
   * generic form elements.
   *
   * @see \Drupal\neo_alchemist\ComponentValuePluginProviderBase::configurationForm()
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, array &$complete_form = NULL) {
    $form += $this->configurationForm($form, $form_state, $complete_form);
    return $form;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Most provider plugins should not override this method. To add validation
   * for specific provider type, override
   * ComponentValuePluginProviderBase::validateForm().
   *
   * @see \Drupal\neo_alchemist\ComponentValuePluginProviderBase::validateForm()
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configurationValidate($form, $form_state);
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $is_default_form = FALSE): array {
    $form['value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Value'),
      '#default_value' => $this->filter->getValue(),
      '#required' => $this->filter->isRequired(),
    ];
    if ($is_default_form) {
      $form['value']['#required'] = $this->filter->isRequired() && !$this->filter->isEditable();
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValue(array $value, array $form, FormStateInterface $form_state): ?string {
    return $value['value'];
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(string $value = NULL): mixed {
    return $value;
  }

}
