<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\views\ViewExecutable;

/**
 * Plugin implementation of the neo_component_slot.
 */
#[ComponentSlot(
  id: 'views_header',
  label: new TranslatableMarkup('Views | Header'),
  description: new TranslatableMarkup('Embed the header for a views display.'),
)]
final class ViewsHeaderSlot extends ViewsSlotBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'context' => '',
      'handlers' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    if (!empty($this->configuration['handlers'])) {
      $summary[] = $this->t('Handlers: @handlers', ['@handlers' => implode(', ', $this->configuration['handlers'])]);
    }
    else {
      $summary[] = $this->t('Handlers: All');
    }
    return $summary;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form = parent::configurationForm($form, $form_state, $complete_form);

    $context = $this->configuration['context'] ?? NULL;
    if ($context) {
      $viewsContexts = $this->component->getPropShapeContexts('views');

      if (isset($viewsContexts[$context]['value'])) {
        /** @var \Drupal\views\ViewExecutable $view */
        $view = $viewsContexts[$context]['value'];
        // Get the header area.
        $header = $view->display_handler->getOption('header');
        if (!empty($header)) {
          $options = [];
          foreach ($header as $id => $options_data) {
            $handler = $view->display_handler->getHandler('header', $id);
            if ($handler) {
              $options[$id] = $handler->admin_label ?: $handler->definition['title'];
            }
          }

          $handlers = $this->configuration['handlers'] ?? [];
          $form['handlers'] = [
            '#type' => 'table',
            '#header' => [
              $this->t('Block'),
              $this->t('Weight'),
            ],
            '#caption' => $this->t('Select which header handlers to include. If none are selected, all will be included.'),
            '#element_validate' => [[get_class($this), 'validateHandlers']],
            '#tabledrag' => [
              [
                'action' => 'order',
                'relationship' => 'sibling',
                'group' => 'handler-weight',
              ],
            ],
          ];
          $count = 0;
          foreach ($header as $id => $options_data) {
            $handler = $view->display_handler->getHandler('header', $id);
            if (!$handler) {
              continue;
            }
            $label = $handler->admin_label ?: $handler->definition['title'];
            $status = in_array($id, $handlers);
            $weight = $status ? array_search($id, $handlers) : $count + 100;
            $form['handlers'][$id]['#attributes']['class'][] = 'draggable';
            $form['handlers'][$id]['#weight'] = $weight;
            $form['handlers'][$id]['status'] = [
              '#type' => 'checkbox',
              '#title' => $label,
              '#default_value' => $status,
            ];
            $form['handlers'][$id]['weight'] = [
              '#type' => 'number',
              '#title' => t('Weight for @title', ['@title' => $label]),
              '#title_display' => 'invisible',
              '#default_value' => 0,
              '#attributes' => ['class' => ['handler-weight']],
            ];
            $count++;
          }

          uasort($form['handlers'], ['Drupal\Component\Utility\SortArray', 'sortByWeightProperty']);
        }
      }
    }

    return $form;
  }

  /**
   * Given the menu form values, clean them into a simple array.
   */
  public static function validateHandlers($element, FormStateInterface $form_state) {
    $values = $form_state->getValue($element['#parents']) ?: [];
    $values = array_filter($values, function ($value) {
      return $value['status'] == 1;
    });
    array_walk($values, function (&$value) {
      unset($value['status'], $value['weight']);
      $value = array_filter($value);
    });
    $form_state->setValueForElement($element, array_keys($values));
  }

  /**
   * Convert the view to a renderable array.
   */
  protected function toViewsRenderable(ViewExecutable $view): array {
    $build = [];
    // Get the header area.
    $header = $view->display_handler->getOption('header');
    $handlers = $this->configuration['handlers'] ?? [];
    if (!empty($header)) {
      foreach ($header as $id => $options) {
        if (!empty($handlers) && !in_array($id, $handlers)) {
          continue;
        }
        $handler = $view->display_handler->getHandler('header', $id);
        if ($handler) {
          $render = $handler->render();
          if (!empty($render)) {
            $build[$id] = $render;
            $cacheableMetadata = new CacheableMetadata();
            $cacheableMetadata->addCacheTags($view->getCacheTags());
            $cacheableMetadata->applyTo($build[$id]);
          }
        }
      }
    }
    return $build;
  }

}
