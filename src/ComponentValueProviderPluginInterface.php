<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * Interface for neo_component_value_provider plugins.
 */
interface ComponentValueProviderPluginInterface extends ConfigurableInterface, PluginFormInterface, PluginInspectionInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

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
   * Determines if this processor should be allowed to process.
   *
   * @param string $op
   *   The operation being performed. Current operations are 'edit',
   *   'calculate' and 'form'.
   *
   * @return bool
   *   TRUE if processing should be allowed, FALSE otherwise.
   */
  public function allowProcessing(string $op): bool;

  /**
   * Determines if following processors should be allowed to process.
   *
   * @return bool
   *   TRUE if processing should continue, FALSE otherwise.
   */
  public function shouldContinueProcessing(): bool;

  /**
   * Called when calculating the field item value.
   *
   * Typically used to set the field item value. Can call
   * stopFurtherProcessing() to stop following processors from processing.
   */
  public function onCalculateFieldItemValue();

  /**
   * Alter the widget form element.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function widgetFormAlter(array &$element, FormStateInterface $form_state);

}
