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
  public function toUrl($rel = NULL, array $options = []) {
    $fieldName = $this->getFieldItem()->getFieldDefinition()->getName();
    $entity = $this->getTargetEntity();
    return match($rel) {
      'edit' => $entity->toUrl("alchemist.$fieldName.edit")->setRouteParameter('uuid', $this->uuid()),
      'delete' => $entity->toUrl("alchemist.{$fieldName}.delete")->setRouteParameter('uuid', $this->uuid()),
      'sort' => $entity->toUrl("alchemist.{$fieldName}.sort")->setRouteParameter('uuid', $this->uuid()),
      default => $entity->toUrl("alchemist.{$fieldName}"),
    };
  }

}
