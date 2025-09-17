<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Defines a component filter.
 */
class ComponentFilter implements ComponentFilterInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The filter manager.
   *
   * @var \Drupal\neo_alchemist\ComponentFilterPluginManager
   */
  protected $manager;

  /**
   * The component.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $component;

  /**
   * The uuid.
   *
   * @var string|null
   */
  protected $uuid;

  /**
   * The title.
   *
   * @var string
   */
  protected $title;

  /**
   * The description.
   *
   * @var string
   */
  protected $description;

  /**
   * The plugin id.
   *
   * @var string
   */
  protected $pluginId;

  /**
   * The plugin settings.
   *
   * @var array
   */
  protected $pluginSettings;

  /**
   * The filter plugin.
   *
   * @var \Drupal\neo_alchemist\ComponentFilterPluginInterface
   */
  protected $plugin;

  /**
   * The default value.
   *
   * @var string|null
   */
  protected ?string $defaultValue;

  /**
   * The override value.
   *
   * @var string|null
   */
  protected ?string $overrideValue;

  /**
   * The processed value.
   *
   * @var string|null
   */
  protected ?string $processedValue;

  /**
   * Flag to indicate if the filter is editable.
   *
   * @var bool
   */
  protected $editable;

  /**
   * Flag to indicate if the filter is required.
   *
   * @var bool
   */
  protected $required;

  /**
   * Constructs a new ComponentSlot object.
   */
  public function __construct(ComponentFilterPluginManager $manager, ComponentInterface $component, $settings = []) {
    $this->manager = $manager;
    $this->component = $component;
    $this->uuid = $settings['uuid'] ?? NULL;
    $this->title = $settings['title'] ?? '';
    $this->description = $settings['description'] ?? '';
    $this->pluginId = $settings['plugin_id'] ?? '';
    $this->pluginSettings = $settings['plugin_settings'] ?? [];
    $this->defaultValue = $settings['value'] ?? NULL;
    $this->overrideValue = NULL;
    $this->editable = (bool) ($settings['editable'] ?? FALSE);
    $this->required = (bool) ($settings['required'] ?? FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return $this->title ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function uuid(): ?string {
    return $this->uuid ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    if ($plugin = $this->getPlugin()) {
      return array_merge([
        $this->t('Type: %plugin_id', ['%plugin_id' => $plugin->label()]),
      ], $plugin->settingsSummary());
    }
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function valueSummary(): ?string {
    if ($plugin = $this->getPlugin()) {
      return $plugin->valueSummary($this->getValue());
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getComponent(): ComponentInterface {
    return $this->component;
  }

  /**
   * {@inheritdoc}
   */
  public function setTitle(string $title): self {
    $this->title = $title;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function setDescription(string $description): self {
    $this->description = $description;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setPluginId(string $pluginId): self {
    if ($this->pluginId !== $pluginId) {
      $this->plugin = NULL;
      $this->pluginSettings = [];
    }
    $this->pluginId = $pluginId;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginId(): string {
    return $this->pluginId;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginSettings(): array {
    return $this->pluginSettings;
  }

  /**
   * {@inheritdoc}
   */
  public function setPluginSettings(array $pluginSettings): self {
    if ($this->pluginSettings !== $pluginSettings) {
      $this->plugin = NULL;
    }
    $this->pluginSettings = $pluginSettings;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getPlugin(): ?ComponentFilterPluginInterface {
    if (!isset($this->plugin)) {
      $this->plugin = NULL;
      $pluginId = $this->getPluginId();
      if ($pluginId && $this->manager->hasDefinition($pluginId)) {
        $this->plugin = $this->manager->createInstance($pluginId, [
          'filter' => $this,
          'settings' => $this->getPluginSettings(),
        ]);
      }
    }
    return $this->plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function isNew(): bool {
    return empty($this->uuid());
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return (bool) $this->editable;
  }

  /**
   * {@inheritdoc}
   */
  public function setEditable(bool $editable): self {
    $this->editable = $editable;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isRequired(): bool {
    return (bool) $this->required;
  }

  /**
   * {@inheritdoc}
   */
  public function setRequired(bool $required): self {
    $this->required = $required;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $is_default_form = FALSE): array {
    if ($this->getPlugin()) {
      return $this->getPlugin()->buildForm($form, $form_state, $is_default_form);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->getPlugin()) {
      $this->getPlugin()->validateForm($form, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValue(array $value, array $form, FormStateInterface $form_state): ?string {
    if ($this->getPlugin()) {
      return $this->getPlugin()->massageFormValue($value, $form, $form_state);
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    return $this->getValue() === NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function allowDefault(): bool {
    return !$this->isRequired() && $this->hasDefaultValue();
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultValue(): ?string {
    return $this->defaultValue;
  }

  /**
   * {@inheritdoc}
   */
  public function setDefaultValue(?string $value): self {
    $this->defaultValue = $value;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefaultValue(): bool {
    return $this->defaultValue !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getOverrideValue(): ?string {
    return $this->overrideValue;
  }

  /**
   * {@inheritdoc}
   */
  public function setOverrideValue(?string $value): self {
    $this->overrideValue = $value;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function hasOverrideValue(): bool {
    return $this->overrideValue !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getValue(): ?string {
    return $this->overrideValue ?? $this->defaultValue;
  }

  /**
   * {@inheritdoc}
   */
  public function getProcessedValue(): ?string {
    if (!isset($this->processedValue)) {
      $this->processedValue = NULL;
      if ($this->getPlugin()) {
        $this->processedValue = $this->getPlugin()->getValue($this->getValue());
      }
    }
    return $this->processedValue;
  }

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return [
      'title' => $this->label(),
      'description' => $this->getDescription(),
      'plugin_id' => $this->getPluginId(),
      'plugin_settings' => $this->getPluginSettings(),
      'value' => $this->getDefaultValue(),
      'editable' => $this->isEditable(),
      'required' => $this->isRequired(),
    ];
  }

}
