<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Slot\ComponentSlotPluginBase;
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
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;

    if ($options = $this->getOptions()) {
      $form['context'] = [
        '#type' => 'select',
        '#title' => $this->t('Views Context'),
        '#description' => $this->t('The context key provided by a value plugin that contains the views object.'),
        '#options' => $options,
        '#empty_option' => $this->t('- Select -'),
        '#default_value' => $this->configuration['context'],
        '#required' => TRUE,
        '#ajax' => [
          'callback' => [static::class, 'refreshAjax'],
          'wrapper' => $wrapperId,
        ],
      ];
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
   * Add the view's cache metadata as a cacheable dependency.
   *
   * Not cheap to call repeatedly on a Search API view: neither
   * CachePluginBase::getCacheTags() nor getCacheMaxAge() is memoized, and
   * SearchApiQuery overrides both to walk $view->result and merge tags per
   * row. Call this once per render, not once per rendered item.
   */
  protected function addViewAsCacheableDependency(ViewExecutable $view): void {
    $this->addCacheableDependency($view->display_handler->getCacheMetadata());

    /** @var \Drupal\views\Plugin\views\cache\CachePluginBase $cache_plugin */
    $cache_plugin = $view->display_handler->getPlugin('cache');
    $cacheableMetadata = $this->getCacheableMetadata();
    // Merge, never set: getCacheableMetadata() hands back the COMPONENT's one
    // shared object, which every slot and value provider on the component
    // writes to. setCacheMaxAge() overwrites, so a permissive view could raise
    // a max-age an earlier contributor had lowered to 0 and quietly overcache
    // the whole component.
    $cacheableMetadata->mergeCacheMaxAge($cache_plugin->getCacheMaxAge());
    $cacheableMetadata->addCacheTags($cache_plugin->getCacheTags());
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentInterface $component): bool {
    return $component->hasPropShapeWithPlugin('views');
  }

}
