<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Entity;

use Drupal\neo_alchemist\ComponentEntityInterface;
use Drupal\neo_alchemist\ComponentFieldInterface;

/**
 * A component instance.
 */
final class ComponentEntity extends ComponentInstanceBase implements ComponentEntityInterface {

  /**
   * {@inheritdoc}
   */
  protected string $scope = 'entity';

  /**
   * The field component associated with this entity.
   *
   * @var \Drupal\neo_alchemist\ComponentFieldInterface|null
   */
  protected ?ComponentFieldInterface $fieldComponent;

  /**
   * {@inheritdoc}
   */
  public function toUrl($rel = NULL, array $options = []) {
    $fieldName = $this->getFieldItem()->getFieldDefinition()->getName();
    $fieldKey = ComponentFieldConfig::getKeyFromFieldname($fieldName);
    $entity = $this->getTargetEntity();
    return match($rel) {
      'edit' => $entity->toUrl("alchemist.edit", $options)->setRouteParameter('neo_field', $fieldKey)->setRouteParameter('neo_component', $this->uuid()),
      'clone' => $entity->toUrl("alchemist.clone", $options)->setRouteParameter('neo_field', $fieldKey)->setRouteParameter('neo_component', $this->uuid()),
      'delete' => $entity->toUrl("alchemist.delete", $options)->setRouteParameter('neo_field', $fieldKey)->setRouteParameter('neo_component', $this->uuid()),
      'sort' => $entity->toUrl("alchemist.sort", $options)->setRouteParameter('neo_field', $fieldKey),
      'preview' => $entity->toUrl("alchemist.preview", $options)->setRouteParameter('neo_field', $fieldKey),
      default => $entity->toUrl("alchemist.manage", $options)->setRouteParameter('neo_field', $fieldKey),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldComponent(): ?ComponentFieldInterface {
    if (!isset($this->fieldComponent)) {
      $this->fieldComponent = NULL;
      $fieldItem = $this->getFieldDefinition()->getFieldItem($this->getTargetEntity());
      $uuid = $this->uuid();
      if ($fieldItem->hasComponent($uuid)) {
        // We load with $noCache to ensure we get the config version of this
        // component.
        $this->fieldComponent = $fieldItem->getComponent($uuid, TRUE);
      }
    }
    return $this->fieldComponent;
  }

}
