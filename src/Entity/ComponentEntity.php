<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\Component\Serialization\Json;

/**
 * A component instance.
 */
final class ComponentEntity extends ComponentInstanceBase {

  /**
   * {@inheritdoc}
   */
  public function toUrl($rel = NULL, array $options = []) {
    $fieldName = $this->getFieldItem()->getFieldDefinition()->getName();
    $entity = $this->getEntity();
    return match($rel) {
      'edit' => $entity->toUrl("alchemist.$fieldName.edit")->setRouteParameter('uuid', $this->uuid()),
      'delete' => $entity->toUrl("alchemist.{$fieldName}.delete")->setRouteParameter('uuid', $this->uuid()),
      'sort' => $entity->toUrl("alchemist.{$fieldName}.sort")->setRouteParameter('uuid', $this->uuid()),
      default => $entity->toUrl("alchemist.{$fieldName}"),
    };
  }

  /**
   * {@inheritDoc}
   */
  public function save() {
    // $entity = $this->getEntity();
    // $entity->set($this->getFieldDefinition()->getName(), $this->getFieldItem()->getValue());
    // $current = Json::decode($this->getFieldItem()->getValue()['props']);
    // $current = reset($current);
    // $new = Json::decode($this->getEntity()->get('field_full')->first()->getValue()['props']);
    // $new = reset($new);
    // kint('current', $current['props']['title']['value']['value'], $current['props']['image']['value']);
    // kint('new', $new['props']['title']['value']['value'], $new['props']['image']['value']);
    // die;
    return $this->getEntity()->save();
  }

  /**
   * {@inheritDoc}
   */
  public function delete() {
    $this->getFieldItem()->removeComponent($this->uuid());
    $this->getEntity()->save();
  }

}
