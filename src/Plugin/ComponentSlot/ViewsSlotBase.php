<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentSlotPluginBase;
use Drupal\views\ViewExecutable;

/**
 * Plugin implementation of the neo_component_slot.
 */
abstract class ViewsSlotBase extends ComponentSlotPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'context' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Views Context: @context', ['@context' => $this->getOptions()[$this->configuration['context']] ?? 'NA']);
    return $summary;
  }

  /**
   * Get the available options for the views context.
   */
  protected function getOptions(): array {
    $options = [];
    if ($viewsContexts = $this->component->getPropShapeContexts('views')) {
      foreach ($viewsContexts as $context => $contextInfo) {
        $options[$context] = $contextInfo['shape']->getTitle();
      }
    }
    return $options;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form = parent::configurationForm($form, $form_state, $complete_form);

    if ($options = $this->getOptions()) {
      $form['context'] = [
        '#type' => 'select',
        '#title' => $this->t('Views Context'),
        '#description' => $this->t('The context key provided by a value plugin that contains the views object.'),
        '#options' => $options,
        '#empty_option' => $this->t('- Select -'),
        '#default_value' => $this->configuration['context'],
        '#required' => TRUE,
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    $context = $this->configuration['context'];
    if (!$context) {
      return [];
    }
    $viewsContexts = $this->component->getPropShapeContexts('views');
    if (!isset($viewsContexts[$context]['value'])) {
      return [];
    }
    /** @var \Drupal\views\ViewExecutable $view */
    $view = $viewsContexts[$context]['value'];
    return $this->toViewsRenderable($view);
  }

  /**
   * Convert the view to a renderable array.
   */
  abstract protected function toViewsRenderable(ViewExecutable $view): array;

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentInterface $component) {
    return $component->hasPropShapeWithPlugin('views');
  }

}
