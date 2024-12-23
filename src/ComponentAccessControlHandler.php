<?php

namespace Drupal\neo_alchemist;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the access control handler for the neo_component entity type.
 *
 * @see \Drupal\neo_alchemist\Entity\Component
 */
class ComponentAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected $viewLabelOperation = TRUE;

}
