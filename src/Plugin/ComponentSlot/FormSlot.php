<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Slot\ComponentSlotPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_slot.
 */
#[ComponentSlot(
  id: 'form',
  label: new TranslatableMarkup('Form'),
  description: new TranslatableMarkup('Embed a form.'),
)]
final class FormSlot extends ComponentSlotPluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected FormBuilderInterface $formBuilder;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentInterface $component,
    string $uuid,
    array $configuration,
    FormBuilderInterface $formBuilder,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $component, $uuid, $configuration);
    $this->formBuilder = $formBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['component'],
      $configuration['uuid'],
      $configuration['settings'],
      $container->get('form_builder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'form_class' => '',
      'form_arg1' => [
        'type' => '',
        'value' => '',
      ],
      'form_arg2' => [
        'type' => '',
        'value' => '',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('Form class: @class', ['@class' => $this->configuration['form_class'] ?: $this->t('None')]);
    if ($this->configuration['form_arg1']['type']) {
      $summary[] = $this->t('Form Argument 1 Type: @type', ['@type' => $this->getArgTypes()[$this->configuration['form_arg1']['type']] ?? $this->t('Unknown')]);
    }
    if ($this->configuration['form_arg2']['type']) {
      $summary[] = $this->t('Form Argument 2 Type: @type', ['@type' => $this->getArgTypes()[$this->configuration['form_arg2']['type']] ?? $this->t('Unknown')]);
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {

    $form['form_class'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Form CSS class'),
      '#description' => $this->t('Example: Drupal\user\AccountSettingsForm'),
      '#default_value' => $this->configuration['form_class'],
      '#required' => TRUE,
    ];

    $form['form_arg1'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Form Argument 1'),
      '#id' => Html::getId($this->getPluginId() . '-form-arg1'),
      '#tree' => TRUE,
    ];
    $form['form_arg1'] = $this->argForm($form['form_arg1'], $form_state, $this->configuration['form_arg1']);

    $form['form_arg2'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Form Argument 2'),
      '#id' => Html::getId($this->getPluginId() . '-form-arg2'),
      '#tree' => TRUE,
    ];
    $form['form_arg2'] = $this->argForm($form['form_arg2'], $form_state, $this->configuration['form_arg2']);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function argForm(array $form, FormStateInterface $form_state, array $configuration): array {
    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Argument Type'),
      '#options' => $this->getArgTypes(),
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $configuration['type'],
      '#ajax' => [
        'callback' => [self::class, 'refreshAjax'],
        'wrapper' => $form['#id'],
      ],
    ];

    if ($configuration['type']) {
      switch ($configuration['type']) {
        case 'raw':
          $form['value'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Raw Value'),
            '#required' => TRUE,
            '#default_value' => $configuration['value'],
          ];
          break;

        case 'shape':
          if ($options = $this->getShapeOptions()) {
            $form['value'] = [
              '#type' => 'select',
              '#title' => $this->t('Shape Name'),
              '#required' => TRUE,
              '#options' => $options,
              '#default_value' => $configuration['value'],
            ];
          }
          break;

        case 'filter':
          if ($options = $this->getFilterOptions()) {
            $form['value'] = [
              '#type' => 'select',
              '#title' => $this->t('Filter'),
              '#required' => TRUE,
              '#options' => $options,
              '#default_value' => $configuration['value'],
            ];
          }
          break;
      }
    }

    return $form;
  }

  /**
   * Ajax callback.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -1));
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    $args = [
      $this->configuration['form_class'],
      $this->getArgValue($this->configuration['form_arg1']),
      $this->getArgValue($this->configuration['form_arg2']),
    ];
    $callback = [$this->formBuilder, 'getForm'];
    return call_user_func_array($callback, $args);
  }

  /**
   * Get the argument value based on its configuration.
   */
  protected function getArgValue(array $configuration) {
    switch ($configuration['type']) {
      case 'raw':
        return $configuration['value'];

      case 'shape':
        $shape = $this->component->getPropShape($configuration['value']);
        if ($shape) {
          return $shape->getValue();
        }
        break;

      case 'filter':
        $filter = $this->component->getFilter($configuration['value']);
        if ($filter) {
          return $filter->getProcessedValue();
        }
        break;
    }
    return NULL;
  }

  /**
   * Get available argument types.
   */
  protected function getArgTypes(): array {
    return [
      'raw' => $this->t('Raw value'),
      'shape' => $this->t('Shape'),
      'filter' => $this->t('Filter'),
    ];
  }

  /**
   * Get available shape options.
   */
  protected function getShapeOptions(): array {
    return array_map(
      fn($shape) => $shape->getTitle(),
      $this->component->getPropShapes(),
    );
  }

  /**
   * Get available filter options.
   */
  protected function getFilterOptions(): array {
    $filters = $this->component->getFilters();
    if ($filters) {
      $options = array_map(fn($filter) => $filter->label(), $filters);
      asort($options);
      return $options;
    }
    return [];
  }

}
