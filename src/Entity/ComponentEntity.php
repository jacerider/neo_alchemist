<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

/**
 * A component instance.
 */
final class ComponentEntity extends ComponentInstanceBase {

  /**
   * {@inheritdoc}
   */
  protected string $scope = 'entity';

  /**
   * {@inheritdoc}
   */
  public function toUrl($rel = NULL, array $options = []) {
    $fieldName = $this->getFieldItem()->getFieldDefinition()->getName();
    $fieldKey = ComponentFieldConfig::getKeyFromFieldname($fieldName);
    $entity = $this->getTargetEntity();
    return match($rel) {
      'edit' => $entity->toUrl("alchemist.edit")->setRouteParameter('neo_field', $fieldKey)->setRouteParameter('neo_component', $this->uuid()),
      'delete' => $entity->toUrl("alchemist.delete")->setRouteParameter('neo_field', $fieldKey)->setRouteParameter('neo_component', $this->uuid()),
      'sort' => $entity->toUrl("alchemist.sort")->setRouteParameter('neo_field', $fieldKey),
      default => $entity->toUrl("alchemist.manage")->setRouteParameter('neo_field', $fieldKey),
    };
  }

}
