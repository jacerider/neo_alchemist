<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Theme\ComponentPluginManager;

/**
 * Builds transient (unsaved) component entities for an SDC.
 *
 * A transient neo_component entity is constructed on the fly so that the
 * Alchemist shape/slot pipeline produces default values from the SDC's
 * `.component.yml` `examples`. This is shared by the browser preview
 * controller and the `neo:alchemist:render` Drush command so both build a
 * component the same way.
 */
final class ComponentPreviewBuilder {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ComponentPluginManager $componentPluginManager,
  ) {}

  /**
   * Builds a transient component entity for an SDC, or NULL if invalid.
   *
   * Uses a deterministic id so preview overrides written by the workspace forms
   * are read back here. Returns NULL for unknown or non-Alchemist SDCs.
   *
   * @param string $component
   *   The SDC component id (e.g. "front:accordion_test").
   * @param bool $preview
   *   Whether to build in preview mode (TRUE, the default) — which exposes
   *   `neoIsPreview` to Twig and enables preview-only behavior — or in runtime
   *   mode (FALSE) so the component renders as it would on a live page.
   *
   * @return \Drupal\neo_alchemist\ComponentInterface|null
   *   The transient component entity, or NULL.
   */
  public function build(string $component, bool $preview = TRUE): ?ComponentInterface {
    $definition = $this->componentPluginManager->hasDefinition($component)
      ? $this->componentPluginManager->getDefinition($component)
      : NULL;
    if (!$definition || empty($definition['neo'])) {
      return NULL;
    }

    /** @var \Drupal\neo_alchemist\ComponentInterface $entity */
    $entity = $this->entityTypeManager->getStorage('neo_component')->create([
      'id' => 'sdc_preview_' . hash('crc32b', $component),
      'label' => $definition['name'] ?? $component,
      'component' => $component,
      'status' => TRUE,
    ]);
    $entity->setPreview($preview);
    return $entity;
  }

}
