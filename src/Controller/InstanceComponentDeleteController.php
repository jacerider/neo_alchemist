<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\ComponentInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentDeleteController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(ComponentInterface $neo_component) {
    return $this->entityFormBuilder()->getForm($neo_component->getTargetEntity(), 'alchemist_delete', [
      'neo_component_instance' => $neo_component,
    ]);
  }

}
