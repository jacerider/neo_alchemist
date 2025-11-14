<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\Ajax\ComponentAjaxHelperTrait;
use Drupal\neo_alchemist\ComponentInstanceInterface;
use Drupal\neo_alchemist\ComponentManageHelper;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentCloneController extends ControllerBase {

  use AjaxHelperTrait;
  use ComponentAjaxHelperTrait;

  /**
   * Builds the response.
   */
  public function __invoke(ComponentInstanceInterface $neo_component) {
    $newNeoComponent = $neo_component->createDuplicate();
    $newNeoComponent->save();

    $fieldItem = $neo_component->getFieldItem();
    $fieldDefinition = $fieldItem->getFieldDefinition();
    $this->messenger()->addStatus($this->t('@op component %name successfully on %label: %field_label.', [
      '@op' => 'Cloned',
      '%name' => $neo_component->label(),
      '%label' => $fieldItem->belongsToFieldConfig() ? $this->entityTypeManager()->getDefinition($fieldDefinition->getTargetEntityTypeId())->getLabel() : $fieldItem->getEntity()->label(),
      '%field_label' => $fieldDefinition->getLabel(),
    ]));

    if ($this->isAjax()) {
      return $this->getComponentReponse(
        ComponentManageHelper::getId($fieldItem),
        $newNeoComponent->uuid(),
        $fieldItem
      );
    }
    $url = $neo_component->toUrl();
    return $this->redirect($url->getRouteName(), $url->getRouteParameters());
  }

}
