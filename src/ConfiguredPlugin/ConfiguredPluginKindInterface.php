<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ConfiguredPlugin;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\ComponentInterface;

/**
 * One family of plugins configured onto a component.
 *
 * Access rules and filters were two of everything: two add controllers that
 * were byte-identical after a rename, two edit controllers differing by two
 * lines, two factories, and two admin forms whose whole difference was the
 * five extra fields a filter carries. Nothing enforced that a change to one
 * reached the other.
 *
 * A kind declares only what actually differs — the manager, the accessors that
 * read, write and delete on the component, the form mode and the label — and
 * one controller and one form serve both. A third family is written by adding
 * an implementation here, not by copying four classes.
 *
 * @see \Drupal\neo_alchemist\Form\ComponentConfiguredPluginForm
 * @see \Drupal\neo_alchemist\Controller\ComponentConfiguredPluginController
 */
interface ConfiguredPluginKindInterface {

  /**
   * The kind's machine name.
   *
   * Doubles as the `neo_component` entity form operation and as the key the
   * controller hands the wrapper to the form under.
   *
   * @return string
   *   'access' or 'filter'.
   */
  public function id(): string;

  /**
   * The kind's human name, as it appears in status messages.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|string
   *   The label, lowercase — it is read mid-sentence ("Added filter Foo.").
   */
  public function label(): TranslatableMarkup|string;

  /**
   * The plugin manager backing this kind.
   *
   * @return \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginManagerBase
   *   The manager, which is what narrows the offered definitions.
   */
  public function getManager(): ConfiguredPluginManagerBase;

  /**
   * Builds a new, unsaved wrapper for a component.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component.
   *
   * @return \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginWrapperInterface
   *   The wrapper.
   */
  public function create(ComponentInterface $component): ConfiguredPluginWrapperInterface;

  /**
   * Loads a wrapper stored on a component.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component.
   * @param string $uuid
   *   The stored uuid.
   *
   * @return \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginWrapperInterface|null
   *   The wrapper, or NULL when the component has nothing under that uuid.
   */
  public function load(ComponentInterface $component, string $uuid): ?ConfiguredPluginWrapperInterface;

  /**
   * Writes a wrapper onto the component.
   *
   * The component is the form's own unsaved copy: nothing persists until the
   * form saves it.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component.
   * @param \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginWrapperInterface $wrapper
   *   The wrapper to stage.
   *
   * @return \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginWrapperInterface
   *   The staged wrapper, which carries a uuid once it has one.
   */
  public function stage(ComponentInterface $component, ConfiguredPluginWrapperInterface $wrapper): ConfiguredPluginWrapperInterface;

  /**
   * Removes a wrapper from the component.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component.
   * @param string $uuid
   *   The stored uuid.
   */
  public function delete(ComponentInterface $component, string $uuid): void;

  /**
   * The render element type wrapping the plugin's own settings form.
   *
   * @return string
   *   'container' or 'fieldset'.
   */
  public function pluginSettingsElementType(): string;

  /**
   * Adds the fields this kind carries beyond a plugin id and its settings.
   *
   * The shared form weights its own elements so a kind can place fields either
   * side of them: the plugin select sits at 0 and its settings at 2.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginWrapperInterface $wrapper
   *   The wrapper being edited.
   * @param array $ajax
   *   The shared form's own '#ajax' definition, ready to attach to any
   *   element whose change should rebuild the form.
   *
   * @return array
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ConfiguredPluginWrapperInterface $wrapper, array $ajax): array;

  /**
   * Reads this kind's own fields back onto the wrapper.
   *
   * Runs after the plugin id and the plugin's settings have been applied, so
   * a field whose form was built from the plugin can massage its value here.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\neo_alchemist\ConfiguredPlugin\ConfiguredPluginWrapperInterface $wrapper
   *   The wrapper being edited.
   */
  public function submitForm(array &$form, FormStateInterface $form_state, ConfiguredPluginWrapperInterface $wrapper): void;

}
