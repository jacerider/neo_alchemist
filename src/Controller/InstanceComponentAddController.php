<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentAddController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(ComponentTreeItem $neo_field, ComponentInterface $neo_component) {
    $instance = $neo_field->createComponent($neo_component);
    return $this->entityFormBuilder()->getForm($instance->getTargetEntity(), 'alchemist', [
      'neo_component_instance' => $instance,
    ]);
  }

  /**
   * Returns the title.
   */
  public function getTitle(ComponentTreeItem $neo_field, ComponentInterface $neo_component) {
    $label = $neo_field->belongsToFieldConfig() ? $neo_field->getEntity()->getEntityType()->getLabel() : $neo_field->getEntity()->label();
    return $this->t('Add %component to %label: %field_label', [
      '%component' => $neo_component->label(),
      '%label' => $label,
      '%field_label' => $neo_field->getFieldDefinition()->getLabel(),
    ]);
  }

}
