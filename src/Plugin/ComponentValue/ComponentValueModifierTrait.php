<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A trait for adding entity type manager.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
trait ComponentValueModifierTrait {

  /**
   * Configuration form for the value provider plugin.
   */
  protected function modifierConfigurationForm(ComponentShapePluginInterface $shape, array $defaults, $form, FormStateInterface $form_state, array &$complete_form): array {
    assert(!empty($form['#parents']), 'The form element must have a #parents key.');
    $wrapperId = Html::getId('modifiers-' . $shape->getName());
    $collection = $shape->getValueCollection();
    $instances = $collection->getInstancesByGroup('modifiers');
    if ($instances) {
      $form['modifiers'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Modifiers'),
        '#element_validate' => [
          [static::class, 'modifierConfigurationValidate'],
        ],
      ];
      foreach ($instances as $pluginName => $instance) {
        $pluginWrapperId = $wrapperId . '-' . $pluginName;
        $isEnabled = isset($defaults[$pluginName]);
        $form['modifiers'][$pluginName] = [
          '#type' => $isEnabled ? 'fieldset' : 'container',
          '#weight' => $instance->getPluginDefinition()['weight'],
          '#attributes' => [
            'id' => $pluginWrapperId,
          ],
        ];
        $form['modifiers'][$pluginName]['status'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable %plugin', [
            '%plugin' => $instance->label(),
          ]),
          '#default_value' => $isEnabled,
          '#ajax' => [
            'callback' => [static::class, 'modifierRefreshAjax'],
            'wrapper' => $pluginWrapperId,
          ],
        ];
        if ($isEnabled) {
          $instance->setConfiguration($defaults[$pluginName]);
          $modifierSettingsForm = [
            '#type' => 'container',
            '#parents' => array_merge($form['#parents'], [
              'modifiers',
              $pluginName,
              'settings',
            ]),
          ];
          $subform_state = SubformState::createForSubform($modifierSettingsForm, $form, $form_state);
          $form['modifiers'][$pluginName]['settings'] = $instance->buildConfigurationForm($modifierSettingsForm, $subform_state, $form);
        }
      }
    }
    return $form;
  }

  /**
   * Ajax callback.
   */
  public static function modifierRefreshAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -1));
  }

  /**
   * Configuration form validation for the value provider plugin.
   */
  public static function modifierConfigurationValidate(array $element, FormStateInterface $form_state) {
    $values = $form_state->getValue($element['#parents']) ?: [];
    foreach ($values as $pluginWrapperId => $value) {
      if (empty($value['status'])) {
        unset($values[$pluginWrapperId]);
        continue;
      }
      $values[$pluginWrapperId] = $value['settings'] ?? [];
    }
    $form_state->setValue($element['#parents'], $values);
  }

}
