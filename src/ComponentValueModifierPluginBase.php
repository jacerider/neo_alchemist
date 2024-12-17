<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\Core\Plugin\PluginWithFormsTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo\Helpers\NestedArray;

/**
 * Base class for neo_component_value_modifier plugins.
 */
abstract class ComponentValueModifierPluginBase extends PluginBase implements ComponentValueModifierPluginInterface, PluginWithFormsInterface {

  use PluginWithFormsTrait;
  use StringTranslationTrait;

  /**
   * The shape.
   *
   * @var \Drupal\neo_alchemist\ComponentShapePluginInterface
   */
  protected ComponentShapePluginInterface $shape;

  /**
   * Flag to continue processing.
   *
   * @var bool
   */
  protected $continueProcessing = TRUE;

  /**
   * Creates a toolbar item instance.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->shape = $shape;
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
    $this->configuration = NestedArray::mergeDeepStrict(
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
   *
   * Creates a generic configuration form for all modifier types. Individual
   * modifier plugins can add elements to this form by overriding
   * ComponentValuePluginModifierBase::modifierForm(). Most modifier plugins
   * should not override this method unless they need to alter the generic form
   * elements.
   *
   * @see \Drupal\neo_alchemist\ComponentValuePluginModifierBase::modifierForm()
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, array &$complete_form = NULL) {
    $form += $this->modifierForm($form, $form_state, $complete_form);
    return $form;
  }

  /**
   * Configuration form for the value modifier plugin.
   */
  protected function modifierForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Most modifier plugins should not override this method. To add validation
   * for specific modifier type, override
   * ComponentValuePluginModifierBase::modifierValidate().
   *
   * @see \Drupal\neo_alchemist\ComponentValuePluginModifierBase::modifierValidate()
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->modifierValidate($form, $form_state);
  }

  /**
   * Form validation for the value modifier plugin configuration.
   */
  protected function modifierValidate(array $form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function modifyValue(mixed $value): mixed {
    return $value;
  }

}
