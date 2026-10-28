<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\neo_alchemist\Slot\ComponentSlotPluginBase;
use Drupal\views\Element\View;
use Drupal\views\ViewExecutable;
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
   * The view element key, and AJAX parameter, naming the slot a view is in.
   *
   * Core's views AJAX controller rebuilds a view from its name, display and
   * arguments alone, so the overrides applied here would be lost on the first
   * AJAX page change. The slot reference travels with the view's AJAX settings
   * so ViewsSlotHooks can reapply them from the saved slot.
   *
   * @see \Drupal\neo_alchemist\Hook\ViewsSlotHooks
   */
  public const AJAX_PARAMETER = 'neo_alchemist_views_slot';

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
    $summary[] = $this->t('View display: %view_display_id', ['%view_display_id' => $this->configuration['view_display_id']]);
    if ($this->configuration['view_items_per_page']) {
      $summary[] = $this->t('Items per page: %items_per_page', ['%items_per_page' => $this->configuration['view_items_per_page']]);
    }
    if ($this->configuration['view_items_offset']) {
      $summary[] = $this->t('Offset items: %items_offset', ['%items_offset' => $this->configuration['view_items_offset']]);
    }
    if ($this->configuration['view_arguments']) {
      $args = [];
      foreach ($this->configuration['view_arguments'] as $filter) {
        // A filter referenced here can have been deleted since; toRenderable()
        // already guards the same lookup.
        if ($filterEntity = $this->component->getFilter($filter)) {
          $args[] = $filterEntity->label();
        }
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
        // An offset of 0 is meaningful — it is "start at the beginning" — and
        // is what the equivalent control on the views value plugin allows.
        '#min' => 0,
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
      '#slot_reference' => $this->component->id() . ':' . $this->uuid(),
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
      $view->setOffset($element['#view_items_offset']);
    }
    $view->setDisplay($element['#view_display_id']);
    static::claimPagerElement($view);
    $view->element['#' . static::AJAX_PARAMETER] = $element['#slot_reference'] ?? NULL;
    $args = [];
    if ($element['#view_arguments']) {
      $arguments = $view->getHandlers('argument');
      if ($arguments) {
        foreach ($arguments as $id => $argument) {
          if (!empty($element['#view_arguments'][$id])) {
            $args[] = $element['#view_arguments'][$id];
          }
          else {
            $args[] = 'all';
          }
        }
      }
    }
    // Pass off to core Views preRender handler.
    return View::preRenderViewElement([
      '#type' => 'view',
      '#view' => $view,
      '#display_id' => $element['#view_display_id'],
      '#arguments' => $args,
      '#embed' => TRUE,
    ]);
  }

  /**
   * Reapplies this slot's overrides to its view rebuilt by views AJAX.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view, with its display set and its pager not yet initialized.
   * @param int|null $pagerElement
   *   The pager element the view was rendered on, as the AJAX request sent it.
   */
  public function applyToAjaxView(ViewExecutable $view, ?int $pagerElement): void {
    // The reference arrives from the browser; only act on the view it names.
    if ($view->id() !== $this->configuration['view_id'] || $view->current_display !== $this->configuration['view_display_id']) {
      return;
    }
    if ($this->configuration['view_items_per_page']) {
      $view->setItemsPerPage($this->configuration['view_items_per_page']);
    }
    if ($this->configuration['view_items_offset']) {
      $view->setOffset($this->configuration['view_items_offset']);
    }
    $view->initPager();
    // Core sends the element back but never applies it. The bound keeps a
    // crafted request from building a huge comma-separated `page` parameter.
    if ($view->usePager() && $pagerElement !== NULL && $pagerElement >= 0 && $pagerElement <= 50) {
      static::setPagerElement($view, $pagerElement);
    }
    $view->element['#' . static::AJAX_PARAMETER] = $this->component->id() . ':' . $this->uuid();
  }

  /**
   * Moves the view onto a pager element nothing else on the page uses.
   *
   * Every view pages on element 0 unless its config says otherwise, so two
   * paged views on one page would read and write the same `page` parameter:
   * paging one pages the other, and empties it when it has fewer pages. The
   * first claimant keeps its configured element, so existing links still land;
   * later ones move past everything registered so far, whether by another
   * views slot or by anything else that paginated first.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view, with its display set.
   */
  protected static function claimPagerElement(ViewExecutable $view): void {
    $view->initPager();
    if (!$view->usePager()) {
      return;
    }
    $claimed = &drupal_static(__METHOD__, []);
    /** @var \Drupal\Core\Pager\PagerManagerInterface $pagerManager */
    $pagerManager = \Drupal::service('pager.manager');
    $element = (int) $view->pager->getPagerId();
    if (isset($claimed[$element]) || $pagerManager->getPager($element) !== NULL) {
      $element = max([$pagerManager->getMaxPagerElementId(), ...array_keys($claimed)]) + 1;
      static::setPagerElement($view, $element);
    }
    $claimed[$element] = TRUE;
  }

  /**
   * Puts an initialized pager on another element.
   *
   * The pager reads its current page when it is initialized, so it reads it
   * again for the new element.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view, with its pager initialized.
   * @param int $element
   *   The pager element.
   */
  public static function setPagerElement(ViewExecutable $view, int $element): void {
    $view->pager->options['id'] = $element;
    $view->pager->setCurrentPage();
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRenderViewElement'];
  }

}
