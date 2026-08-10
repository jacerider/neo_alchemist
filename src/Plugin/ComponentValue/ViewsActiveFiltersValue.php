<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ViewsActiveFiltersTwig;

/**
 * Fills a views_active_filters prop with a bound view's applied filters.
 *
 * The designed-markup replacement for the active_filters module's views area:
 * every applied exposed-filter value becomes an item carrying the resolved
 * option label (real term names for hierarchical taxonomy filters, the
 * entered text for text filters) and a remove_url that toggles just that
 * value out; clear_url drops every exposed filter at once. The template
 * renders the chips however it likes — items are plain links.
 *
 * Covers ALL exposed filters with current input. Resolution timing and the
 * ordering constraint match the sibling provider (see the base class).
 *
 * @see \Drupal\neo_alchemist\ViewsActiveFiltersTwig
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsExposedFilterValue
 */
#[ComponentValue(
  id: 'views_active_filters',
  label: new TranslatableMarkup('Views | Active Filters'),
  description: new TranslatableMarkup('Provide the applied exposed filters as removable-chip data.'),
  group: 'providers',
  ref_types: [
    'views_active_filters',
  ],
  // The base class takes and returns ViewExecutable in real signatures, so
  // this plugin cannot function — or even be safely offered — without views.
  provider: 'views',
)]
final class ViewsActiveFiltersValue extends ViewsExposedFilterValueBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'context' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form = parent::configurationForm($form, $form_state, $complete_form);
    $form['#id'] = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    return $this->buildContextFormElement($form);
  }

  /**
   * {@inheritdoc}
   *
   * Modify-stage resolution for the same reasons as the sibling provider —
   * see ViewsExposedFilterValue::modifyValue().
   */
  public function modifyValue(mixed $value): mixed {
    $resolved = $this->resolveActiveFilters();
    if ($resolved !== NULL) {
      return new ViewsActiveFiltersTwig($resolved);
    }
    return $this->shape->getComponent()->isPreview() ? $value : [];
  }

  /**
   * Resolves the applied exposed filters into the prop value.
   *
   * @return array|null
   *   The value, or NULL when no view context is resolved.
   */
  protected function resolveActiveFilters(): ?array {
    $context = $this->configuration['context'];
    if (!$context) {
      return NULL;
    }
    $view = $this->getContextView($context);
    if (!$view) {
      return NULL;
    }

    $this->addQueryCacheability();

    $query = $this->getRequestQuery();
    $clearQuery = $query;
    $items = [];

    foreach ($this->getExposedFilterHandlers($view) as $identifier => $handler) {
      $current = $this->getCurrentValues($view, $identifier);
      if (!$current) {
        continue;
      }
      unset($clearQuery[$identifier]);

      $filterLabel = (string) ($handler->options['expose']['label'] ?? $identifier);
      // Option labels where options exist; the raw value (search text) where
      // they don't.
      $labels = [];
      foreach ($this->getFlatOptions($view, $identifier, $handler) as $option) {
        $labels[$option['value']] = $option['label'];
      }

      foreach ($current as $value) {
        $removeQuery = $query;
        $remaining = array_values(array_diff($current, [$value]));
        if ($remaining) {
          $removeQuery[$identifier] = count($remaining) === 1 && empty($handler->options['expose']['multiple'])
            ? reset($remaining)
            : $remaining;
        }
        else {
          unset($removeQuery[$identifier]);
        }
        $items[] = [
          'param' => $identifier,
          'filter_label' => $filterLabel,
          'value' => $value,
          'label' => $labels[$value] ?? $value,
          'remove_url' => $this->buildUrl($removeQuery),
        ];
      }
    }

    return [
      'active' => (bool) $items,
      'count' => count($items),
      'clear_url' => $this->buildUrl($clearQuery),
      'items' => $items,
    ];
  }

}
