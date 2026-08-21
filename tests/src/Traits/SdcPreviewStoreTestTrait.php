<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Traits;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\neo_alchemist\ComponentInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Seeds SDC preview-workspace prop values without a cache backend.
 *
 * The prop-value overrides that drive the SDC preview workspace used to be a
 * cache entry a test seeded by calling Component::setPreviewValues() — which is
 * why so many shape tests reached for a global cache backend simply to set up a
 * shape's value. That mechanism moved behind the SDC preview store on the
 * editor-state seam (SdcPreviewStore), and this trait points that store at the
 * in-memory adapter for the test: register() swaps the store's cache backend
 * for the dumb in-memory one, so seeding a prop value never writes through a
 * cache backend. Both the seed helpers here and the render path
 * (Component::getValues()) read the same in-memory-backed store service, so a
 * seeded value is visible when the component renders.
 *
 * Using this trait is the acceptance signal the seam is in the right place: a
 * kernel test that still needs a cache backend to seed a shape's value would be
 * one the seam missed.
 */
trait SdcPreviewStoreTestTrait {

  /**
   * Points the SDC preview store at the in-memory adapter for the test.
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    if ($container->hasDefinition('neo_alchemist.sdc_preview_store')) {
      $container->getDefinition('neo_alchemist.sdc_preview_store')
        ->replaceArgument(0, new Reference('neo_alchemist.editor_state.memory'));
    }
  }

  /**
   * Seeds a component's preview prop-value overrides.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   * @param array $values
   *   The overrides, structured like a placed instance's values.
   */
  protected function setPreviewValues(ComponentInterface $component, array $values): void {
    \Drupal::service('neo_alchemist.sdc_preview_store')->setValues($component, $values);
  }

  /**
   * Reads a component's preview prop-value overrides.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   *
   * @return array
   *   The seeded overrides, or an empty array when none are stored.
   */
  protected function getPreviewValues(ComponentInterface $component): array {
    return \Drupal::service('neo_alchemist.sdc_preview_store')->getValues($component);
  }

  /**
   * Whether a component has any preview prop-value override stored.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   *
   * @return bool
   *   TRUE when at least one override is stored, FALSE otherwise.
   */
  protected function hasPreviewValues(ComponentInterface $component): bool {
    return \Drupal::service('neo_alchemist.sdc_preview_store')->hasValues($component);
  }

  /**
   * Clears a component's preview prop-value overrides.
   *
   * @param \Drupal\neo_alchemist\ComponentInterface $component
   *   The component being previewed.
   */
  protected function resetPreviewValues(ComponentInterface $component): void {
    \Drupal::service('neo_alchemist.sdc_preview_store')->resetValues($component);
  }

}
