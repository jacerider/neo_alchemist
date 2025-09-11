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
  id: 'views_header',
  label: new TranslatableMarkup('Views | Header'),
  description: new TranslatableMarkup('Embed the header for a views display.'),
)]
final class ViewsHeaderSlot extends ViewsSlotBase {

  /**
   * Convert the view to a renderable array.
   */
  protected function toViewsRenderable(ViewExecutable $view): array {
    $build = [];
    // Get the header area.
    $header = $view->display_handler->getOption('header');
    if (!empty($header)) {
      foreach ($header as $id => $options) {
        $handler = $view->display_handler->getHandler('header', $id);
        if ($handler) {
          $render = $handler->render();
          if (!empty($render)) {
            $build[$id] = $render;
          }
        }
      }
    }
    return $build;
  }

}
