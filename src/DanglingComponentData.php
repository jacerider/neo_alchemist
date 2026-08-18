<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;

/**
 * Finds component placements whose component no longer exists.
 *
 * A component tree stores the neo_component config entity id verbatim, and
 * resolves it at render with Component::load(). When that returns NULL the
 * instance is skipped — see ComponentTreeHydrated::getValue() — and if the
 * missing instance is a parent, its whole subtree goes with it. The page still
 * returns 200. It is simply blank where the component used to be.
 *
 * Nothing repairs content when this happens. Config hosts are covered: a field
 * config or Alchemist block embedding the component is repaired through
 * onDependencyRemoval(). Content entities carry no config dependency on a
 * component, so deleting or renaming one leaves every node that placed it
 * pointing at nothing, silently and indefinitely.
 *
 * This class exists to make that state findable. It is deliberately a diff
 * rather than another sweep — ComponentUsage already knows every id stored in
 * every tree, so a dangling id is just one that no entity answers to.
 *
 * Inert rows are excluded, because ComponentUsage::getCounts() excludes them:
 * a locked field's row is never read back, so a dangling id inside one is not
 * breaking any page and would only be noise here.
 *
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeHydrated::getValue()
 * @see \Drupal\neo_alchemist\InertComponentData
 */
final class DanglingComponentData {

  /**
   * Constructs a DanglingComponentData object.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ComponentUsage $componentUsage,
    protected RendererInterface $renderer,
  ) {}

  /**
   * Finds every component id that is placed but does not exist.
   *
   * @param bool $refresh
   *   (optional) Recompute rather than trusting the cached usage tally. The
   *   tally is cache-tag invalidated by content and config changes, so the
   *   default is accurate for reporting; pass TRUE when acting on the result.
   *
   * @return array
   *   Keyed by missing component id, each:
   *   - count: how many places reference it.
   *   - places: list of ['label', 'url', 'context'].
   */
  public function scan(bool $refresh = FALSE): array {
    // The whole scan runs inside a render context. ComponentUsage resolves
    // host labels while it sweeps, and an entity label carrying a neo_icon
    // element throws from __toString when no context is open — which is
    // exactly the situation of the callers that matter most here
    // (hook_requirements under `drush core:requirements`, and the integrity
    // command). Labels are then flattened to strings so what this returns is
    // data, safe to json_encode or print anywhere.
    return $this->renderer->executeInRenderContext(new RenderContext(), function () use ($refresh): array {
      if ($refresh) {
        $this->componentUsage->reset();
      }
      $referenced = array_keys($this->componentUsage->getCounts());
      if (!$referenced) {
        return [];
      }

      $existing = $this->entityTypeManager->getStorage('neo_component')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('id', $referenced, 'IN')
        ->execute();

      $dangling = array_diff($referenced, array_values($existing));
      if (!$dangling) {
        return [];
      }

      $found = [];
      foreach ($dangling as $componentId) {
        $usages = $this->componentUsage->getUsages($componentId);
        // 'inert' is deliberately dropped: those rows do not render, so a
        // dangling id inside one breaks nothing.
        $places = array_merge(
          $usages['content'] ?? [],
          $usages['default'] ?? [],
          $usages['block'] ?? [],
        );
        foreach ($places as $delta => $place) {
          $places[$delta]['label'] = isset($place['label']) ? (string) $place['label'] : '';
          $places[$delta]['context'] = isset($place['context']) ? (string) $place['context'] : '';
          // A Url object here would fail the moment anything printed or
          // json-encoded the result, which is all this is for.
          $url = $place['url'] ?? NULL;
          $places[$delta]['url'] = $url instanceof Url ? $url->toString() : (string) ($url ?? '');
        }
        $found[$componentId] = [
          'count' => count($places),
          'places' => $places,
        ];
      }
      ksort($found);
      return $found;
    });
  }

  /**
   * Counts the placements pointing at a component that does not exist.
   */
  public function count(bool $refresh = FALSE): int {
    return array_sum(array_column($this->scan($refresh), 'count'));
  }

  /**
   * Gets the cache tags the scan result depends on.
   */
  public function getCacheTags(): array {
    return array_merge(
      $this->componentUsage->getCacheTags(),
      $this->entityTypeManager->getDefinition('neo_component')->getListCacheTags(),
    );
  }

}
