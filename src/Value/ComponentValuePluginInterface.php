<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Value;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;

/**
 * Interface for neo_component_value_modifier plugins.
 */
interface ComponentValuePluginInterface extends ConfigurableInterface, PluginFormInterface, PluginInspectionInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Returns a short summary of the plugin's configured state.
   *
   * Shown wherever the plugin is listed without its form — the provider lists
   * on the prop form and the props table on the component manage page — so a
   * site builder can tell what a plugin is wired to without opening it. Return
   * an empty array when the plugin has nothing configured worth surfacing.
   *
   * @return array
   *   An array of translated summary lines, most important first.
   */
  public function settingsSummary(): array;

  /**
   * Get the shape which owns this plugin.
   *
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
   *   The shape which owns this plugin.
   */
  public function getShape(): ComponentShapePluginInterface;

  /**
   * Return the translated plugin group.
   */
  public function getGroup(): string;

  /**
   * Whether this plugin sources its own value — the producer role.
   *
   * Ask this, never the group string, when the answer drives behavior: a
   * plugin's group is also its sort weight and its form tab, so a plugin filed
   * under a group for the form's sake must not thereby change whether it is
   * treated as a value source. A producer declares itself by implementing
   * ComponentValueProducerInterface; the group is only weight and placement.
   *
   * @return bool
   *   TRUE if the plugin sources its own value, FALSE for the terminal
   *   fallback, modifiers and settings.
   *
   * @see \Drupal\neo_alchemist\Value\ComponentValueProducerInterface
   */
  public function isValueProducer(): bool;

  /**
   * Whether the plugin allows inline usage.
   *
   * Inline usage means that a plugin can be configured on child components
   * when doing nested value assignment.
   */
  public function allowInline(): bool;

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
   * Called when a component is updated.
   */
  public function onUpdate(): void;

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
   * Produce this producer's outcome for the provide phase.
   *
   * The provider search calls this and interprets the result: a producer no
   * longer mutates itself to claim a value, it returns one. The default derives
   * the outcome from provideDefaultValue() — the produced value is offered for
   * the site builder's processing mode to decide. A producer that must claim a
   * value itself — a veto, or the configured default fallback — overrides this
   * to return a claimed provision.
   *
   * @param mixed $value
   *   The value threaded into this producer — the running result of the search.
   *
   * @return \Drupal\neo_alchemist\Value\ComponentValueProvision
   *   The producer's outcome: the value it produced and whether it claims it.
   *
   * @see \Drupal\neo_alchemist\ValueProviderSearch::search()
   */
  public function provide(mixed $value): ComponentValueProvision;

  /**
   * Provide an override value for the component.
   *
   * An extension point with no implementations in this module — every shipped
   * plugin inherits the base class's pass-through. Two things follow for anyone
   * who does implement it:
   *
   * - This pass carries the value a person AUTHORED, not a provider's answer.
   *   The site-builder-configurable processing mode deliberately does not apply
   *   here, and the override pass has no claim of its own — it is a chain, so
   *   every implementer runs in order. A plugin that needs to suppress authored
   *   content does so by returning the suppressed value, and should be sure
   *   that is what it means to do.
   * - Adding an implementation is a conscious decision, pinned by a test that
   *   fails when one appears.
   *
   * @param mixed $value
   *   The value to provide an override for. May be NULL if no override value
   *   has yet been set.
   * @param mixed $defaultValue
   *   The default value.
   *
   * @return mixed
   *   The override value.
   *
   * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait
   * @see \Drupal\Tests\neo_alchemist\Kernel\ComponentValueProcessingModeScopeTest::testNoShippedPluginImplementsProvideOverrideValue()
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
   * Modifies the component value when rendering.
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
   * @param array $submitted_values
   *   The values before they have been massaged by the widget.
   * @param array $original_values
   *   The original values.
   * @param array $form
   *   The parent form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The parent form state.
   */
  public function massageValuesAlter(array &$values, array $submitted_values, array $original_values, array $form, FormStateInterface $form_state): void;

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
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The shape that should be checked.
   *
   * @return bool
   *   TRUE if the provider can be used, FALSE otherwise.
   */
  public static function isApplicable(ComponentShapePluginInterface $shape);

}
