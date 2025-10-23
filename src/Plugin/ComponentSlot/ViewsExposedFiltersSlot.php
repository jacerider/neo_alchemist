<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\views\ViewExecutable;

/**
 * Plugin implementation of the neo_component_slot.
 */
#[ComponentSlot(
  id: 'views_exposed_filters',
  label: new TranslatableMarkup('Views | Exposed Filters'),
  description: new TranslatableMarkup('Embed the exposed filters for a views display.'),
)]
final class ViewsExposedFiltersSlot extends ViewsSlotBase {

  /**
   * Convert the view to a renderable array.
   */
  protected function toViewsRenderable(ViewExecutable $view): array {
    /** @var \Drupal\views\Plugin\views\exposed_form\ExposedFormPluginInterface $plugin */
    $plugin = $view->display_handler->getPlugin('exposed_form');
    $form = $plugin->renderExposedForm();
    $cacheableMetadata = new CacheableMetadata();
    $cacheableMetadata->addCacheTags($view->getCacheTags());
    $cacheableMetadata->applyTo($form);
    return $form;
  }

}
