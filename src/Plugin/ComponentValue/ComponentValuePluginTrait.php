<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\neo_alchemist\ComponentShapePluginInterface;

/**
 * A trait for adding entity type manager.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
trait ComponentValuePluginTrait {

  /**
   * Configuration form for the value provider plugin.
   */
  protected function buildPluginConfigurationForm(ComponentShapePluginInterface $shape, array $defaults, $form, FormStateInterface $form_state): array {
    assert(!empty($form['#parents']), 'The form element must have a #parents key.');
    $wrapperId = Html::getId('modifiers-' . $shape->getName());
    $collection = $shape->getValueCollection();
    $defaultPlugins = $shape->getDefaultPlugins();
    foreach ($defaultPlugins as $pluginId => $settings) {
      if (!isset($defaults[$pluginId])) {
        $defaults[$pluginId] = [
          'status' => TRUE,
          'settings' => $settings,
        ];
      }
    }
    $instances = $collection->getInlineInstances();
    $hasEnabled = !empty(array_filter($defaults, function ($item) {
      return !empty($item['status']);
    }));
    if ($instances) {
      $form['plugins'] = [
        '#type' => 'details',
        '#title' => $this->t('Value Plugins'),
        '#neo_size' => 'xs',
        '#open' => $hasEnabled,
      ];
      foreach ($instances as $pluginName => $instance) {
        $pluginWrapperId = $wrapperId . '-' . $pluginName;
        $pluginStatus = !empty($defaults[$pluginName]['status']);
        $pluginConfiguration = $defaults[$pluginName]['settings'] ?? [];
        $form['plugins'][$pluginName] = [
          '#type' => $pluginStatus ? 'fieldset' : 'container',
          '#weight' => $instance->getPluginDefinition()['weight'],
          '#attributes' => [
            'id' => $pluginWrapperId,
          ],
        ];
        $form['plugins'][$pluginName]['status'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable %plugin', [
            '%plugin' => $instance->label(),
          ]),
          '#neo_size' => 'xs',
          '#description' => $instance->getPluginDefinition()['description'],
          '#default_value' => $pluginStatus,
          '#ajax' => [
            'callback' => [static::class, 'pluginsRefreshAjax'],
            'wrapper' => $pluginWrapperId,
          ],
        ];
        if ($pluginStatus) {
          $instance->setConfiguration($pluginConfiguration);
          $pluginSettingsForm = [
            '#type' => 'fieldset',
            '#title' => $this->t('@label Settings', ['@label' => $instance->label()]),
            '#neo_size' => 'xs',
            '#parents' => array_merge($form['#parents'], [
              'plugins',
              $pluginName,
              'settings',
            ]),
          ];
          $subform_state = SubformState::createForSubform($pluginSettingsForm, $form, $form_state);
          $form['plugins'][$pluginName]['settings'] = $instance->buildConfigurationForm($pluginSettingsForm, $subform_state, $form);
        }
      }
    }
    return $form;
  }

  /**
   * Ajax callback.
   */
  public static function pluginsRefreshAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -1));
  }

}
