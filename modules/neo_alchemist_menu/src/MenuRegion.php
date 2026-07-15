<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_menu;

use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\menu_link_content\MenuLinkContentInterface;
use Drupal\menu_link_content\Plugin\Menu\MenuLinkContent as MenuLinkContentPlugin;

/**
 * Shared helpers for mega menu region menu items.
 *
 * A region item is a menu_link_content entity flagged with the
 * self::OPTION_KEY link option. It renders as an Alchemist component tree
 * (stored in its self::FIELD_NAME field) inside its parent item's mega menu
 * panel instead of as a link.
 */
final class MenuRegion {

  /**
   * The component tree field attached to menu_link_content.
   */
  public const FIELD_NAME = 'field_components';

  /**
   * The Alchemist field key derived from FIELD_NAME ("field_" stripped).
   *
   * Used as the {neo_field} route parameter of the alchemist.* routes.
   *
   * @see \Drupal\neo_alchemist\Entity\ComponentFieldConfig::getKeyFromFieldname()
   */
  public const FIELD_KEY = 'components';

  /**
   * The link options key flagging a menu item as a region.
   */
  public const OPTION_KEY = 'neo_region';

  /**
   * Checks whether a set of link options flags the item as a region.
   *
   * @param array $options
   *   A link field / Url object options array.
   *
   * @return bool
   *   TRUE when the options carry the region flag.
   */
  public static function isRegionOptions(array $options): bool {
    return !empty($options[self::OPTION_KEY]);
  }

  /**
   * Checks whether a menu link content entity is flagged as a region.
   *
   * @param \Drupal\menu_link_content\MenuLinkContentInterface $entity
   *   The menu link content entity.
   *
   * @return bool
   *   TRUE when the entity is a region item.
   */
  public static function isRegionItem(MenuLinkContentInterface $entity): bool {
    return self::isRegionOptions($entity->getUrlObject()->getOptions());
  }

  /**
   * Loads the menu link content entity backing a built menu tree item.
   *
   * @param array $item
   *   An item from MenuLinkTree::build()'s #items, carrying original_link.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface|null
   *   The entity, or NULL when the item is not an entity-backed link.
   */
  public static function loadFromPluginItem(array $item): ?MenuLinkContentInterface {
    $link = $item['original_link'] ?? NULL;
    return $link instanceof MenuLinkInterface ? self::loadFromLink($link) : NULL;
  }

  /**
   * Loads the menu link content entity backing a menu link plugin.
   *
   * @param \Drupal\Core\Menu\MenuLinkInterface $link
   *   The menu link plugin.
   *
   * @return \Drupal\menu_link_content\MenuLinkContentInterface|null
   *   The entity, or NULL when the link is not an entity-backed link.
   */
  public static function loadFromLink(MenuLinkInterface $link): ?MenuLinkContentInterface {
    if (!$link instanceof MenuLinkContentPlugin) {
      return NULL;
    }
    $metadata = $link->getMetaData();
    if (empty($metadata['entity_id'])) {
      return NULL;
    }
    $entity = \Drupal::entityTypeManager()
      ->getStorage('menu_link_content')
      ->load($metadata['entity_id']);
    return $entity instanceof MenuLinkContentInterface ? $entity : NULL;
  }

}
