<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Slot;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginWithFormsTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_alchemist\ComponentInterface;

/**
 * Base class for neo_component_slot plugins.
 */
abstract class ComponentSlotPluginBase extends PluginBase implements ComponentSlotPluginInterface {

  use PluginWithFormsTrait;
  use StringTranslationTrait;

  /**
   * The component.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected ComponentInterface $component;

  /**
   * The plugin UUID.
   *
   * @var string
   */
  protected string $uuid;

  /**
   * The cacheable metadata.
   *
   * @var \Drupal\Core\Cache\CacheableMetadata
   */
  protected CacheableMetadata $cacheableMetadata;

  /**
   * Creates a toolbar item instance.
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentInterface $component,
    string $uuid,
    array $configuration,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
    $this->component = $component;
    $this->uuid = $uuid;
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
  public function uuid(): string {
    return $this->uuid;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    return [];
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
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, ?array &$complete_form = NULL) {
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
  public function getCacheableMetadata(): CacheableMetadata {
    return $this->component->getCacheableMetadata();
  }

  /**
   * {@inheritdoc}
   */
  public function addCacheableDependency($dependency) {
    $this->getCacheableMetadata()->addCacheableDependency($dependency);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentInterface $component): bool {
    // By default, plugins are available for all shapes.
    return TRUE;
  }

}
