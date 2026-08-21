<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Builds the entity-type and bundle select elements the provider forms share.
 *
 * The "pick an entity type, then a bundle" pair was hand-built three times over
 * — on EntityLoadValue, EntityQueryValue and EntitySlot — with the option list,
 * the sort and the element skeleton copied and quietly drifting (two wrote the
 * title "Entity Type", the third "Entity type"). This is the single place that
 * pair is built, so a change to it lands in all three at once. Per-call
 * differences (a content-only filter, a description, a multi-value bundle
 * select) are arguments or an $overrides array, not fresh copies.
 *
 * Static and service-free: it takes the entity-type manager or bundle-info
 * service the caller already holds, so a value plugin and a slot plugin — which
 * share no base — can both reach it.
 */
final class EntityTypeSelectBuilder {

  /**
   * Builds the "Entity type" select element.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param string $default
   *   The currently selected entity type id.
   * @param array $ajax
   *   The element's #ajax definition (callback + wrapper).
   * @param bool $contentOnly
   *   TRUE to offer only content entity types (the default), FALSE for all.
   * @param array $overrides
   *   Render-array keys to merge over the defaults — e.g. a #description. A key
   *   present here wins over the same key in the defaults below.
   *
   * @return array
   *   The select render element.
   */
  public static function entityTypeSelect(EntityTypeManagerInterface $entityTypeManager, string $default, array $ajax, bool $contentOnly = TRUE, array $overrides = []): array {
    $options = [];
    foreach ($entityTypeManager->getDefinitions() as $type) {
      if ($contentOnly && !$type instanceof ContentEntityTypeInterface) {
        continue;
      }
      $options[$type->id()] = $type->getLabel();
    }
    asort($options);
    return $overrides + [
      '#type' => 'select',
      '#title' => new TranslatableMarkup('Entity type'),
      '#options' => $options,
      '#default_value' => $default,
      '#empty_option' => new TranslatableMarkup('- Select -'),
      '#required' => TRUE,
      '#ajax' => $ajax,
    ];
  }

  /**
   * Builds a bundle select for a given entity type.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   * @param string $entityTypeId
   *   The entity type whose bundles to list.
   * @param string|array $default
   *   The currently selected bundle, or bundles for a multi-value select.
   * @param array $ajax
   *   The element's #ajax definition (callback + wrapper).
   * @param array $overrides
   *   Render-array keys to merge over the defaults — e.g. a #title, an
   *   #empty_option, a #multiple flag or a #required flag. A key present here
   *   wins over the same key in the defaults below.
   *
   * @return array
   *   The select render element.
   */
  public static function bundleSelect(EntityTypeBundleInfoInterface $bundleInfo, string $entityTypeId, string|array $default, array $ajax, array $overrides = []): array {
    $options = array_map(
      static fn (array $bundle) => $bundle['label'],
      $bundleInfo->getBundleInfo($entityTypeId),
    );
    asort($options);
    return $overrides + [
      '#type' => 'select',
      '#title' => new TranslatableMarkup('Bundle'),
      '#options' => $options,
      '#default_value' => $default,
      '#empty_option' => new TranslatableMarkup('- Select -'),
      '#ajax' => $ajax,
    ];
  }

}
