<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Slot\ComponentSlotPluginBase;

/**
 * Plugin implementation of the neo_component_slot.
 */
#[ComponentSlot(
  id: 'entity_query_pager',
  label: new TranslatableMarkup('Entity Query | Pager'),
  description: new TranslatableMarkup('Embed a pager for an entity query.'),
)]
final class EntityQueryPagerSlot extends ComponentSlotPluginBase {

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    return [
      '#type' => 'pager',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentInterface $component): bool {
    return $component->hasPropShapeWithPlugin('entity_query');
  }

}
