<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentValuePluginBase;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'widget',
  label: new TranslatableMarkup('Widget'),
  description: new TranslatableMarkup('Provide widget form alterations.'),
  group: 'providers',
  allow_on_default: TRUE,
  weight: 900
)]
final class WidgetValue extends ComponentValuePluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'settings' => [],
    ];
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    if ($widget = $this->shape->getWidget()) {
      $form['settings'] = $widget->settingsForm($form, $form_state);
    }
    if (isset($form['#wrapper_id']) && $this->shape->getValueCollection()->getStatus('default')) {
      $form['reload'] = [
        '#type' => 'submit',
        '#value' => $this->t('Reload default value provider'),
        '#submit' => [[get_class($this), 'configurationFormWidgetReload']],
        '#ajax' => [
          'callback' => [get_class($this), 'configurationFormWidgetAjax'],
          'wrapper' => $form['#wrapper_id'],
        ],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public static function configurationFormWidgetReload(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public static function configurationFormWidgetAjax(array $form, FormStateInterface $form_state): array {
    $button = $form_state->getTriggeringElement();
    $key = array_search('widget', $button['#array_parents']);
    $parents = array_slice($button['#array_parents'], 0, $key);
    return NestedArray::getValue($form, $parents);
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $widget = $this->shape->getWidget();
    if ($widget) {
      $value = $form_state->getValues()['settings'] ?? NULL;
      if ($value) {
        $settings = $widget->massageFormValues($form_state->getValues()['settings'], $form, $form_state);
        $form_state->setValue('settings', $settings);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onShapeInit() {
    parent::onShapeInit();
    $this->shape->setWidgetSettings($this->configuration['settings']);
  }

  /**
   * {@inheritdoc}
   */
  public function isAllowed(string $op): bool {
    if ($op === 'manage') {
      $widget = $this->shape->getWidget();
      $form_state = new FormState();
      return $widget !== NULL && !empty($widget->settingsForm([], $form_state));
    }
    return parent::isAllowed($op);
  }

}
