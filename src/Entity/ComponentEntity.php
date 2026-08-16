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
  protected ComponentFieldInterface|false|null $fieldComponent = NULL;

  /**
   * {@inheritdoc}
   */
  public function toUrl($rel = NULL, array $options = []) {
    $fieldName = $this->getFieldItem()->getFieldDefinition()->getName();
    $fieldKey = ComponentFieldConfig::getKeyFromFieldname($fieldName);
    $entity = $this->getTargetEntity();
    return match($rel) {
      'edit' => $entity->toUrl('alchemist.edit', $options)->setRouteParameter('neo_field', $fieldKey)->setRouteParameter('neo_component', $this->uuid()),
      'region' => $entity->toUrl('alchemist.region', $options)->setRouteParameter('neo_field', $fieldKey)->setRouteParameter('neo_component', $this->id()),
      'clone' => $entity->toUrl('alchemist.clone', $options)->setRouteParameter('neo_field', $fieldKey)->setRouteParameter('neo_component', $this->uuid()),
      'delete' => $entity->toUrl('alchemist.delete', $options)->setRouteParameter('neo_field', $fieldKey)->setRouteParameter('neo_component', $this->uuid()),
      'sort' => $entity->toUrl('alchemist.sort', $options)->setRouteParameter('neo_field', $fieldKey),
      'preview' => $entity->toUrl('alchemist.preview', $options)->setRouteParameter('neo_field', $fieldKey),
      default => $entity->toUrl('alchemist.manage', $options)->setRouteParameter('neo_field', $fieldKey),
    };
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldComponent(): ?ComponentFieldInterface {
    // FALSE means "looked, found nothing" — distinct from NULL, "not looked
    // yet". A plain nullable could not tell those apart, so every miss re-ran
    // the lookup, and the lookup is expensive: it clones the host entity's
    // field list and re-parses the stored tree and props. A miss is the common
    // case on an allow_custom field, where only components that also appear in
    // the field's default layout can hit.
    if ($this->fieldComponent === NULL) {
      $this->fieldComponent = FALSE;
      $fieldItem = $this->getFieldDefinition()->getFieldItem($this->getTargetEntity());
      $uuid = $this->uuid();
      if ($fieldItem->hasComponent($uuid)) {
        // We load with $noCache to ensure we get the config version of this
        // component.
        $this->fieldComponent = $fieldItem->getComponent($uuid);
      }
    }
    return $this->fieldComponent ?: NULL;
  }

}
