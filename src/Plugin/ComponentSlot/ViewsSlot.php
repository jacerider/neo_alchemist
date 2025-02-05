<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\neo_alchemist\ComponentSlotPluginBase;
use Drupal\views\Views;

/**
 * Plugin implementation of the neo_component_slot.
 */
#[ComponentSlot(
  id: 'views',
  label: new TranslatableMarkup('Views'),
  description: new TranslatableMarkup('Embed a view.'),
)]
final class ViewsSlot extends ComponentSlotPluginBase implements TrustedCallbackInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'view_id' => '',
      'view_display_id' => '',
      'view_items_per_page' => NULL,
      'view_items_offset' => NULL,
      'view_arguments' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    $summary[] = $this->t('View: %view', ['%view' => $this->configuration['view_id']]);
    $summary[] = $this->t('View display: %view_display_id', ['%view' => $this->configuration['view_display_id']]);
    if ($this->configuration['view_items_per_page']) {
      $summary[] = $this->t('Items per page: %items_per_page', ['%items_per_page' => $this->configuration['view_items_per_page']]);
    }
    if ($this->configuration['view_items_offset']) {
      $summary[] = $this->t('Offset items: %items_offset', ['%items_offset' => $this->configuration['view_items_offset']]);
    }
    if ($this->configuration['view_arguments']) {
      $args = [];
      foreach ($this->configuration['view_arguments'] as $id => $filter) {
        $args[] = $this->component->getFilter($filter)->label();
      }
      if ($args) {
        $summary[] = $this->t('Arguments: %args', ['%args' => implode(', ', $args)]);
      }
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;
    $viewId = $this->configuration['view_id'];

    $options = [];
    foreach (Views::getEnabledViews() as $view) {
      $options[$view->id()] = $view->label() . ' (' . $view->id() . ')';
    }
    asort($options);

    $form['view_id'] = [
      '#type' => 'select',
      '#title' => $this->t('View'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $viewId,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if ($viewId) {
      $view = Views::getView($viewId);
      if (!$view) {
        return $form;
      }
      $viewDisplayId = $this->configuration['view_display_id'];
      $displayOptions = [];
      foreach ($view->storage->get('display') as $display) {
        $displayOptions[$display['id']] = $display['display_title'];
      }

      $form['view_display_id'] = [
        '#type' => 'select',
        '#title' => $this->t('View Display'),
        '#options' => $displayOptions,
        '#empty_option' => $this->t('- Select -'),
        '#default_value' => $viewDisplayId,
        '#required' => TRUE,
        '#ajax' => [
          'callback' => [static::class, 'refreshAjax'],
          'wrapper' => $wrapperId,
        ],
      ];

      $form['view_items_per_page'] = [
        '#type' => 'number',
        '#title' => $this->t('Override items per page'),
        '#default_value' => $this->configuration['view_items_per_page'] ?? NULL,
        '#min' => 1,
      ];

      $form['view_items_offset'] = [
        '#type' => 'number',
        '#title' => $this->t('Offset items'),
        '#default_value' => $this->configuration['view_items_offset'] ?? NULL,
        '#min' => 1,
      ];

      if ($viewDisplayId) {
        $view->setDisplay($viewDisplayId);
        $display = $view->getDisplay();

        $arguments = $view->getHandlers('argument');
        if (!empty($arguments)) {
          $filters = $this->component->getFilters();
          if ($filters) {
            $options = [
              'all' => $this->t('- Ignore -'),
            ] + array_map(fn($filter) => $filter->label(), $filters);
            asort($options);
            $form['view_arguments'] = [
              '#type' => 'fieldset',
              '#title' => $this->t('View Arguments'),
              '#access' => FALSE,
            ];
            foreach ($arguments as $id => $argument) {
              $handler = $display->getHandler('argument', $id);
              if ($handler) {
                $form['view_arguments']['#access'] = TRUE;
                $form['view_arguments'][$id] = [
                  '#type' => 'select',
                  '#title' => $handler->adminLabel(),
                  '#options' => $options,
                  '#empty_option' => $this->t('- Select -'),
                  '#default_value' => $this->configuration['view_arguments'][$id] ?? NULL,
                ];
              }
            }
          }
        }
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $itemsPerPage = $form_state->getValue('view_items_per_page');
    $form_state->setValue('view_items_per_page', $itemsPerPage ? (int) $itemsPerPage : NULL);
    $offset = $form_state->getValue('view_items_offset');
    $form_state->setValue('view_items_offset', $offset ? (int) $offset : NULL);
    $form_state->setValue('view_arguments', array_filter($form_state->getValue('view_arguments', [])));
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
    $viewArguments = [];
    if ($this->configuration['view_arguments']) {
      foreach ($this->configuration['view_arguments'] as $id => $uuid) {
        $viewArguments[$id] = 'all';
        if ($filter = $this->component->getFilter($uuid)) {
          $viewArguments[$id] = $filter->getProcessedValue();
        }
      }
    }
    return [
      '#view_id' => $this->configuration['view_id'],
      '#view_display_id' => $this->configuration['view_display_id'],
      '#view_items_per_page' => $this->configuration['view_items_per_page'],
      '#view_items_offset' => $this->configuration['view_items_offset'],
      '#view_arguments' => $viewArguments,
      '#pre_render' => [
        [static::class, 'preRenderViewElement'],
      ],
    ];
  }

  /**
   * View element pre render callback.
   */
  public static function preRenderViewElement($element) {
    $view = Views::getView($element['#view_id']);
    if (!$view) {
      return $element;
    }
    if ($element['#view_items_per_page']) {
      $view->setItemsPerPage($element['#view_items_per_page']);
    }
    if ($element['#view_items_offset']) {
      $view->setOffset(1);
    }
    $view->setDisplay($element['#view_display_id']);
    if ($element['#view_arguments']) {
      $arguments = $view->getHandlers('argument');
      if ($arguments) {
        $args = [];
        foreach ($arguments as $id => $argument) {
          if (!empty($element['#view_arguments'][$id])) {
            $args[] = $element['#view_arguments'][$id];
          }
          else {
            $args[] = 'all';
          }
        }
        $view->setArguments($args);
      }
    }
    return $view->executeDisplay($element['#view_display_id']);
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRenderViewElement'];
  }

}
