<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

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
  weight: 900
)]
final class WidgetValue extends ComponentValuePluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'widget' => [],
    ];
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    if ($widget = $this->shape->getWidget()) {
      $form['widget'] = $widget->settingsForm($form, $form_state);
    }
    return $form;
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $widget = $this->shape->getWidget();
    if ($widget) {
      $value = $form_state->getValues()['widget'] ?? NULL;
      if ($value) {
        $widgetValues = $widget->massageFormValues($form_state->getValues()['widget'], $form, $form_state);
        $form_state->setValue('widget', $widgetValues);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onShapeInit() {
    parent::onShapeInit();
    $this->shape->setWidgetSettings($this->configuration['widget']);
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
