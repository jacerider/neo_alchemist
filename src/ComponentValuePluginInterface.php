<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * Interface for neo_component_value_modifier plugins.
 */
interface ComponentValuePluginInterface extends ConfigurableInterface, PluginFormInterface, PluginInspectionInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Return the translated plugin group.
   */
  public function getGroup(): string;

  /**
   * Check if the plugin is allowed on default shape.
   *
   * @return bool
   *   TRUE if the plugin is allowed on default shape, FALSE otherwise.
   */
  public function allowOnDefault(): bool;

  /**
   * Called when a prop is added to a component.
   */
  public function onAdd(): void;

  /**
   * Called when a prop is removed from a component.
   */
  public function onRemove(): void;

  /**
   * Massages the form value using the plugin if available.
   *
   * @param array $values
   *   The form values to be massaged.
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return string|null
   *   The massaged form values.
   */
  public function massageFormValue(array $values, array $form, FormStateInterface $form_state): array;

  /**
   * Called when the shape is initialized.
   *
   * Can be used to change the shapes type or other properties.
   */
  public function onShapeInit();

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
   *   The value to provide an override for. May be NULL if no override value
   *   has yet been set.
   * @param mixed $defaultValue
   *   The default value.
   *
   * @return mixed
   *   The override value.
   */
  public function provideOverrideValue(mixed $value, mixed $defaultValue): mixed;

  /**
   * Modifies the default/override value.
   *
   * This method is called twice. After the default values are built and then
   * after the override values are built. This allows the plugin to modify the
   * values before they are used.
   *
   * @param mixed $value
   *   The value to be modified.
   * @param string $type
   *   Either 'default' or 'override'.
   *
   * @return mixed
   *   The unmodified value.
   */
  public function alterValue(mixed $value, string $type): mixed;

  /**
   * Modifies the given value.
   *
   * This method takes a value of any type and returns the modified value.
   *
   * @param mixed $value
   *   The value to be modified.
   *
   * @return mixed
   *   The unmodified value.
   */
  public function modifyValue(mixed $value): mixed;

  /**
   * Alter the widget form element.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function formAlter(array &$element, FormStateInterface $form_state);

  /**
   * Massage the form values.
   *
   * @param array $values
   *   The form values.
   * @param array $original_values
   *   The original values.
   * @param array $form
   *   The parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The parent form state.
   */
  public function massageValuesAlter(array &$values, array $original_values, array $form, FormStateInterface $form_state): void;

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
   * Determines if the component value is editable.
   *
   * If any value processor sets this to FALSE, the value will not be editable.
   *
   * @return bool
   *   TRUE if the component value is editable, FALSE otherwise.
   */
  public function isEditable(): bool;

  /**
   * Determines if this plugin should be allowed act on the current operation.
   *
   * @param string $op
   *   The operation being performed.
   *
   *   - default: Can act on the default value.
   *   - value: Can act on the value.
   *   - edit: Can control the editability of the prop.
   *   - modify: Can modify the final value.
   *   - form: Can alter the form element.
   *   - manage: Can enable/disable the plugin on a prop.
   *   - default_shape: Can apply the plugin to the default shape.
   *
   * @return bool
   *   TRUE if processing should be allowed, FALSE otherwise.
   */
  public function isAllowed(string $op): bool;

  /**
   * Returns if the provider can be used for the provided shape.
   *
   * @param \Drupal\neo_alchemist\ComponentShapePluginInterface $shape
   *   The shape that should be checked.
   *
   * @return bool
   *   TRUE if the provider can be used, FALSE otherwise.
   */
  public static function isApplicable(ComponentShapePluginInterface $shape);

}
