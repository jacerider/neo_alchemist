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
  id: 'views_exposed_filters',
  label: new TranslatableMarkup('Views | Exposed Filters'),
  description: new TranslatableMarkup('Embed the exposed filters for a views display.'),
)]
final class ViewsExposedFiltersSlot extends ViewsSlotBase {

  /**
   * Convert the view to a renderable array.
   */
  protected function toViewsRenderable(ViewExecutable $view): array {
    $this->addViewAsCacheableDependency($view);

    // ViewExecutable::build() already built this during execute() and memoized
    // it here, and core reads the memo back rather than re-calling.
    // renderExposedForm() memoizes nothing of its own — each call is a full
    // FormBuilder run, so every hook_form_alter and every exposed handler's
    // build/validate/submit would fire a second time.
    // @see \Drupal\views\ViewExecutable::build()
    // @see \Drupal\views\Plugin\views\display\DisplayPluginBase::elementPreRender()
    if (!empty($view->exposed_widgets)) {
      return $view->exposed_widgets;
    }

    // Empty is legitimate — a display set to render its exposed form as a
    // block leaves the memo empty on purpose — so fall back to building it.
    /** @var \Drupal\views\Plugin\views\exposed_form\ExposedFormPluginInterface $plugin */
    $plugin = $view->display_handler->getPlugin('exposed_form');
    return $plugin->renderExposedForm();
  }

}
