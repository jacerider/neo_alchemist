<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\Ajax\InstanceComponentManageIframeCommand;
use Drupal\neo_alchemist\ComponentInstanceInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentCloneController extends ControllerBase {

  use AjaxHelperTrait;

  /**
   * Builds the response.
   */
  public function __invoke(ComponentInstanceInterface $neo_component) {
    $neo_component->createDuplicate()->save();

    $fieldItem = $neo_component->getFieldItem();
    $fieldDefinition = $fieldItem->getFieldDefinition();
    $this->messenger()->addStatus($this->t('@op component %name successfully on %label: %field_label.', [
      '@op' => 'Cloned',
      '%name' => $neo_component->label(),
      '%label' => $fieldItem->belongsToFieldConfig() ? $this->entityTypeManager()->getDefinition($fieldDefinition->getTargetEntityTypeId())->getLabel() : $fieldItem->getEntity()->label(),
      '%field_label' => $fieldDefinition->getLabel(),
    ]));

    if ($this->isAjax()) {
      $response = new AjaxResponse();
      $response->addCommand(new InstanceComponentManageIframeCommand());
      return $response;
    }
    $url = $neo_component->toUrl();
    return $this->redirect($url->getRouteName(), $url->getRouteParameters());
  }

}
