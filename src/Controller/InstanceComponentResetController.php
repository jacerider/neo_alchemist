<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentResetController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(ComponentTreeItem $neo_field) {
    $entity = $neo_field->getEntity();
    return $this->entityFormBuilder()->getForm($entity, 'alchemist_reset', [
      'fieldItem' => $neo_field,
    ]);
  }

}
