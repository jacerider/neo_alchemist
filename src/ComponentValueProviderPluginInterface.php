<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Form\FormStateInterface;

/**
 * Interface for neo_component_value_provider plugins.
 */
interface ComponentValueProviderPluginInterface extends ComponentValuePluginInterface {

  /**
   * Determines if the component value is editable.
   *
   * If any value processor sets this to FALSE, the value will not be editable.
   *
   * @return bool
   *   TRUE if the component value is editable, FALSE otherwise.
   */
  public function isEditable(): bool;

  /**
   * Allow the processing by setting the continue flag to FALSE.
   *
   * This will allow any following value providers to be processed.
   *
   * @return self
   *   The current instance of the class.
   */
  public function allowFurtherProcessing(): self;

  /**
   * Stops the processing by setting the continue flag to FALSE.
   *
   * This will prevent any following value providers from being processed.
   *
   * @return self
   *   The current instance of the class.
   */
  public function stopFurtherProcessing(): self;

  /**
   * Determines if following processors should be allowed to process.
   *
   * @return bool
   *   TRUE if processing should continue, FALSE otherwise.
   */
  public function shouldContinueProcessing(): bool;

  /**
   * Provide a default value for the component.
   *
   * @param mixed $value
   *   The value to provide a default for.
   *
   * @return mixed
   *   The default value.
   */
  public function provideDefaultValue(mixed $value): mixed;

  /**
   * Provide an override value for the component.
   *
   * @param mixed $value
   *   The value to provide an override for.
   *
   * @return mixed
   *   The override value.
   */
  public function provideOverrideValue(mixed $value): mixed;

  /**
   * Alter the widget form element.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function formAlter(array &$element, FormStateInterface $form_state);

}
