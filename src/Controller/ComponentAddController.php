<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class ComponentAddController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(string $component): array {
    $entity = $this->entityTypeManager()->getStorage('neo_component')->create([
      'component' => $component,
    ]);
    return $this->entityFormBuilder()->getForm($entity, 'add');
  }

}
