<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Core\Url;
use Drupal\neo_alchemist\ComponentFieldInterface;

/**
 * A component instance.
 */
final class ComponentField extends ComponentInstanceBase implements ComponentFieldInterface {

  /**
   * {@inheritdoc}
   */
  protected string $scope = 'field';

  /**
   * {@inheritdoc}
   */
  public function toUrl($rel = NULL, array $options = []) {
    $fieldName = $this->getFieldItem()->getFieldDefinition()->getName();
    $entityTypeId = $this->getTargetEntity()->getEntityTypeId();
    return match($rel) {
      'edit' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.{$fieldName}.edit", ['uuid' => $this->uuid()] + $this->getFieldDefinition()->getUrlParameters()),
      'delete' => Url::fromRoute("entity.{$entityTypeId}.field_ui.alchemist.{$fieldName}.delete", ['uuid' => $this->uuid()] + $this->getFieldDefinition()->getUrlParameters()),
      default => $this->getFieldDefinition()->toUrl($rel, $options),
    };
  }

}
