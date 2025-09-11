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
    $exposedInput = [];
    return $view->renderPager($exposedInput);
  }

}
