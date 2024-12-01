<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentSortController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(Request $request, ComponentTreeItem $neo_field) {
    return $this->entityFormBuilder()->getForm($neo_field->getEntity(), 'alchemist_sort', [
      'fieldItem' => $neo_field,
      'uuid' => $request->query->get('uuid'),
    ]);
  }

  /**
   * Returns the title.
   */
  public function getTitle(ComponentTreeItem $neo_field) {
    $label = $neo_field->belongsToFieldConfig() ? $neo_field->getEntity()->getEntityType()->getLabel() : $neo_field->getEntity()->label();
    return $this->t('Sort the components on %label: %field_label', [
      '%label' => $label,
      '%field_label' => $neo_field->getFieldDefinition()->getLabel(),
    ]);
  }

}
