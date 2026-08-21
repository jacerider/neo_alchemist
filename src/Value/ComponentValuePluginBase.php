<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Value;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginWithFormsTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo\Helpers\NestedArray;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;

/**
 * Base class for neo_component_value plugins.
 */
abstract class ComponentValuePluginBase extends PluginBase implements ComponentValuePluginInterface {

  use PluginWithFormsTrait;
  use StringTranslationTrait;

  /**
   * The shape.
   *
   * @var \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
   */
  protected ComponentShapePluginInterface $shape;

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
      // Declaring ComponentValueProcessingModeInterface is enough: the base
      // merges the mode's default configuration, so a producer no longer
      // appends processingModeDefaultConfiguration() to its own defaults and
      // cannot forget to. The method is on the interface, so the instanceof
      // guarantees it exists.
      $this instanceof ComponentValueProcessingModeInterface ? $this->processingModeDefaultConfiguration() : [],
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
  public function getShape(): ComponentShapePluginInterface {
    return $this->shape;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroup(): string {
    return $this->pluginDefinition['group'] ?? '';
  }

  /**
   * {@inheritdoc}
   *
   * The producer role is declared by the interface, not the group. The group
   * is honoured only as a compatibility shim for a producer defined in another
   * package that does not implement the interface (on this site,
   * neo_site_settings). Every producer shipped by this module declares the
   * interface, so for a shipped plugin the interface alone decides and this
   * shim never fires; ComponentValueProducerScopeTest pins that agreement for
   * the producers the base install discovers.
   */
  public function isValueProducer(): bool {
    return $this instanceof ComponentValueProducerInterface || $this->getGroup() === 'providers';
  }

  /**
   * {@inheritdoc}
   */
  public function allowInline(): bool {
    return !empty($this->pluginDefinition['inline']);
  }

  /**
   * {@inheritdoc}
   */
  public function allowOnDefault(): bool {
    return $this->pluginDefinition['allow_on_default'] ?? FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Creates a generic configuration form for all value plugins. Individual
   * plugins can add elements to this form by overriding
   * ComponentValuePluginBase::configurationForm(). Most plugins should not
   * override this method unless they need to alter the generic form elements.
   *
   * @see \Drupal\neo_alchemist\Value\ComponentValuePluginBase::configurationForm()
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, ?array &$complete_form = NULL) {
    $form += $this->configurationForm($form, $form_state, $complete_form);
    // Declaring ComponentValueProcessingModeInterface is enough: the base wires
    // the "Processing" mode select so a producer no longer calls
    // buildProcessingModeForm() from its own form and cannot forget to. The
    // select carries #weight -10, so it sorts above the plugin's own settings
    // however late the base appends it.
    if ($this instanceof ComponentValueProcessingModeInterface) {
      $form = $this->buildProcessingModeForm($form, $form_state);
    }
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
   * Most plugins should not override this method. To add validation for a
   * specific plugin, override
   * ComponentValuePluginBase::configurationValidate().
   *
   * @see \Drupal\neo_alchemist\Value\ComponentValuePluginBase::configurationValidate()
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
  public function massageFormValue(array $values, array $form, FormStateInterface $form_state): array {
    return $this->configurationMassage($values, $form, $form_state);
  }

  /**
   * Massage the form values.
   */
  protected function configurationMassage(array $values, array $form, FormStateInterface $form_state): array {
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {}

  /**
   * Ajax callback: re-renders the subtree the triggering element lives in.
   *
   * The single refresh callback every value plugin's #ajax points at. A control
   * that rebuilds the provider form (an entity-type select, a bundle select…)
   * wires `'callback' => [static::class, 'refreshAjax']` with a `'wrapper'` of
   * the form's own `#id`, and this returns the element one level up from the
   * trigger so the whole provider form re-renders in place. A plugin whose
   * trigger sits at a different depth overrides this — HeadingValue does, and
   * its docblock says why.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -1));
  }

  /**
   * {@inheritdoc}
   */
  public function onAdd(): void {
  }

  /**
   * {@inheritdoc}
   */
  public function onRemove(): void {
  }

  /**
   * {@inheritdoc}
   */
  public function onUpdate(): void {
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
   *
   * Most producers just produce a value and let the site builder's processing
   * mode decide its fate, so the base offers whatever provideDefaultValue()
   * returns. A producer that claims a value itself overrides this.
   */
  public function provide(mixed $value): ComponentValueProvision {
    return ComponentValueProvision::offer($this->provideDefaultValue($value));
  }

  /**
   * {@inheritdoc}
   */
  public function provideOverrideValue(mixed $value, mixed $defaultValue): mixed {
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function alterValue(mixed $value, string $type): mixed {
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function modifyValue(mixed $value): mixed {
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function formAlter(array &$element, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function massageValuesAlter(array &$values, array $submitted_values, array $original_values, array $form, FormStateInterface $form_state): void {
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isAllowed(string $op): bool {
    if ($op === 'default_shape') {
      // By default, plugins are not allowed to act on the default shape.
      return FALSE;
    }
    // By default, plugins are allowed to act on all other operations.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    // By default, plugins are available for all shapes.
    return TRUE;
  }

}
