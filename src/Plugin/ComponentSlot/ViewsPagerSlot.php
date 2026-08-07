<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\views\ViewExecutable;

/**
 * Plugin implementation of the neo_component_slot.
 */
#[ComponentSlot(
  id: 'views_pager',
  label: new TranslatableMarkup('Views | Pager'),
  description: new TranslatableMarkup('Embed a pager for a views display.'),
)]
final class ViewsPagerSlot extends ViewsSlotBase {

  /**
   * Convert the view to a renderable array.
   */
  protected function toViewsRenderable(ViewExecutable $view): array {
    // The display-level boolean, not ViewExecutable::renderPager() — it is how
    // a display says it should not emit a pager at all (Attachment overrides
    // it to return FALSE).
    if (!$view->display_handler->renderPager()) {
      return [];
    }
    $this->addViewAsCacheableDependency($view);
    // Pass the view's own exposed input rather than an empty array. For
    // filters carried in the URL this makes no difference — PagerManager
    // re-merges the current request query into every pager link — but it is
    // the only carrier when the input is not in the query string: filters
    // remembered in the session, input set programmatically via
    // setExposedInput(), a Views AJAX request (where the real request is a
    // POST), or two views on one page with different exposed state.
    // @see \Drupal\views\Plugin\views\display\DisplayPluginBase::elementPreRender()
    $build = $view->renderPager($view->getExposedInput());
    return is_array($build) ? $build : [];
  }

}
