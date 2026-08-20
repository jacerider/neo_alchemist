<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Match;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\FieldLabelOptionsTrait;

/**
 * A trait for adding entity type manager.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
trait FieldFormatterTrait {

  use FieldLabelOptionsTrait;

  /**
   * The formatter plugin manager.
   *
   * @var \Drupal\Core\Field\FormatterPluginManager
   */
  protected FormatterPluginManager $fieldFormatterManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Returns the default configuration for the formatter.
   *
   * @return array
   *   The default configuration for the formatter.
   */
  protected function formatterDefaultConfiguration() {
    return [
      'field_plugin' => '',
      'field_label' => 'hidden',
      'field_settings' => [],
      'field_third_party_settings' => [],
    ];
  }

  /**
   * Returns the summary of the formatter settings.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface|null $field
   *   The field definition.
   * @param array $configuration
   *   The configuration array.
   *
   * @return array
   *   An array of summary strings.
   */
  protected function formatterSettingsSummary(FieldDefinitionInterface $field, array $configuration): array {
    $summary = [];
    if ($field) {
      $summary[] = $this->t('Field: @label (@name)', [
        '@label' => $field->getLabel(),
        '@name' => $field->getName(),
      ]);
    }
    if ($pluginId = $configuration['field_plugin']) {
      $pluginOptions = $this->getFormatterPluginOptions($field);
      if (isset($pluginOptions[$pluginId])) {
        $summary[] = $this->t('Display format: @label', [
          '@label' => $pluginOptions[$pluginId],
        ]);

        $plugin = $this->getFormatterPlugin($pluginId, $field, $configuration);
        if ($plugin) {
          if ($settingsSummary = $plugin->settingsSummary()) {
            $summary = array_merge($summary, $settingsSummary);
          }
        }
      }
    }
    return $summary;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function formatterConfigurationForm(array $form, FormStateInterface $form_state, FieldDefinitionInterface $field, array $configuration = [], array $ajax = []): array {
    $configuration += $this->formatterDefaultConfiguration();
    $form['field_label'] = [
      '#type' => 'select',
      '#title' => $this->t('Label display'),
      '#options' => $this->getFieldLabelOptions(),
      '#default_value' => $configuration['field_label'],
      '#required' => TRUE,
    ];
    if ($pluginOptions = $this->getFormatterPluginOptions($field)) {
      $pluginId = $configuration['field_plugin'];
      $form['field_plugin'] = [
        '#type' => 'select',
        '#title' => $this->t('Display format'),
        '#options' => $pluginOptions,
        '#default_value' => $pluginId,
        '#required' => TRUE,
        '#empty_option' => $this->t('- Select -'),
        '#ajax' => $ajax,
      ];
      if ($pluginId && $this->fieldFormatterManager()->hasDefinition($pluginId)) {
        $plugin = $this->getFormatterPlugin($pluginId, $field, $configuration);
        if ($plugin) {
          $form['field_settings'] = $plugin->settingsForm($form, $form_state);

          $settings_form = [];
          // Invoke hook_field_widget_third_party_settings_form(), keying
          // resulting subforms by module name.
          $this->moduleHandler()->invokeAllWith(
            'field_formatter_third_party_settings_form',
            function (callable $hook, string $module) use (&$settings_form, $plugin, $field, &$form, $form_state) {
              $settings_form[$module] = $hook(
                $plugin,
                $field,
                'full',
                $form,
                $form_state
              );
            }
          );

          $form['field_third_party_settings'] = $settings_form;
        }
      }
    }
    return $form;
  }

  /**
   * Returns an array of applicable widget or formatter options for a field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   *
   * @return array
   *   An array of applicable widget or formatter options.
   */
  protected function getFormatterPluginOptions(FieldDefinitionInterface $field_definition) {
    $options = $this->fieldFormatterManager()->getOptions($field_definition->getType());
    $applicable_options = [];
    foreach ($options as $option => $label) {
      $plugin_class = DefaultFactory::getPluginClass($option, $this->fieldFormatterManager()->getDefinition($option));
      if ($plugin_class::isApplicable($field_definition)) {
        $applicable_options[$option] = $label;
      }
    }
    return $applicable_options;
  }

  /**
   * Get the formatter plugin.
   *
   * @param string $pluginId
   *   The plugin ID.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field definition.
   * @param array $configuration
   *   The configuration array.
   *
   * @return \Drupal\Core\Field\FormatterInterface|false
   *   The formatter plugin.
   */
  protected function getFormatterPlugin(string $pluginId, FieldDefinitionInterface $field, array $configuration): mixed {
    return $this->fieldFormatterManager()->getInstance([
      'field_definition' => $field,
      'view_mode' => 'full',
      'prepare' => FALSE,
      'configuration' => [
        'type' => $pluginId,
        'label' => $configuration['field_label'],
        'settings' => $configuration['field_settings'],
        'third_party_settings' => $configuration['field_third_party_settings'],
        'region' => 'content',
      ],
    ]);
  }

  /**
   * Get the field formatter manager.
   *
   * @return \Drupal\Core\Field\FormatterPluginManager
   *   The field formatter manager.
   */
  protected function fieldFormatterManager(): FormatterPluginManager {
    if (!isset($this->fieldFormatterManager)) {
      $this->fieldFormatterManager = \Drupal::service('plugin.manager.field.formatter');
    }
    return $this->fieldFormatterManager;
  }

  /**
   * Get the module handler service.
   *
   * @return \Drupal\Core\Extension\ModuleHandlerInterface
   *   The module handler service.
   */
  protected function moduleHandler() {
    if (!isset($this->moduleHandler)) {
      $this->moduleHandler = \Drupal::service('module_handler');
    }
    return $this->moduleHandler;
  }

}
