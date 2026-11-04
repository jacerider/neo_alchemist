<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\ComponentInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class ComponentCloneController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(ComponentInterface $neo_component): array {
    // Component::createDuplicate() rebuilds a clean entity from the exported
    // values, without the source's ids or thumbnail.
    $clone = $neo_component->createDuplicate();
    return $this->entityFormBuilder()->getForm($clone, 'clone');
  }

}
