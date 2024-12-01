<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentEditController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(ComponentInterface $neo_component) {
    return $this->entityFormBuilder()->getForm($neo_component->getTargetEntity(), 'alchemist_edit', [
      'neo_component_instance' => $neo_component,
    ]);
  }

  /**
   * Returns the title.
   */
  public function getTitle(ComponentTreeItem $neo_field, ComponentInterface $neo_component) {
    $label = $neo_field->belongsToFieldConfig() ? $neo_field->getEntity()->getEntityType()->getLabel() : $neo_field->getEntity()->label();
    return $this->t('Edit %component from %label: %field_label', [
      '%component' => $neo_component->label(),
      '%label' => $label,
      '%field_label' => $neo_field->getFieldDefinition()->getLabel(),
    ]);
  }

}
