<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Form\FormStateInterface;

/**
 * Base class for neo_component_value_modifier plugins.
 */
abstract class ComponentValueModifierPluginBase extends ComponentValuePluginBase implements ComponentValueModifierPluginInterface {

  /**
   * Flag to continue processing.
   *
   * @var bool
   */
  protected $continueProcessing = TRUE;

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
