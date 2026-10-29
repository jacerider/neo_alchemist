<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Hands the page's likely largest image priority: its first component's.
 *
 * The first component of a page's main content is usually what fills the
 * screen on arrival — a hero, a banner — so its first image is the page's
 * likely largest contentful paint. neo_image's priority grant
 * (`neo_image.priority`) loads that image eagerly at high fetch priority and
 * preloads it from the page head; this decides which component's render the
 * grant is offered to, and offers it.
 *
 * **Which component.** The first that renders at the root of the first tree,
 * in display order, of the page's main content — the trees the formatter
 * marks as page content (see PageHeading). Header blocks, mega menu panels
 * and entities embedded in another view mode never take part. Within that
 * component, the first neo_image render that does not decline (`priority:
 * false` on the image) claims it, children included. A component whose
 * `.component.yml` declares `priority: false` declines for the page: when it
 * comes first, nothing gets priority.
 *
 * **Why from the tree.** neo_image claims a grant as an image renders, which a
 * render-cache hit skips. So the component is chosen from the tree's data,
 * before rendering, and the grant is wrapped around that component's build:
 * the choice and the preload it produces live in the same cache entry as the
 * tree, and a reorder — a change to the host entity — invalidates both.
 *
 * Without neo_image, nothing is granted.
 *
 * @see \Drupal\neo_image\NeoImagePriority
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeHydrated::renderify()
 */
final class PagePriority implements TrustedCallbackInterface {

  /**
   * The neo_image grant service.
   */
  private const GRANT_SERVICE = 'neo_image.priority';

  /**
   * Constructs the service.
   *
   * @param \Drupal\neo_alchemist\PageHeading $pageHeading
   *   Which trees are the page's main content, and in what order.
   * @param \Drupal\Core\Theme\ComponentPluginManager $componentPluginManager
   *   The SDC plugin manager, for a component's `priority` declaration.
   * @param object|null $imagePriority
   *   neo_image's grant service, or NULL when neo_image is not installed.
   */
  public function __construct(
    private readonly PageHeading $pageHeading,
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly ?object $imagePriority = NULL,
  ) {}

  /**
   * Whether a tree's root component is offered the page's priority grant.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The tree.
   * @param string $uuid
   *   The first root component the tree renders.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Collects what the answer depends on beyond the tree itself: the trees
   *   rendered before it on the page.
   *
   * @return bool
   *   TRUE when the component's render should be granted priority.
   */
  public function grants(ComponentTreeItem $item, string $uuid, CacheableMetadata $cacheability): bool {
    if ($this->imagePriority === NULL || !$this->pageHeading->isPageContent($item)) {
      return FALSE;
    }
    $entity = $item->getEntity();
    if ($entity instanceof ContentEntityInterface) {
      $fieldName = $item->getFieldDefinition()->getName();
      foreach ($this->pageHeading->pageItems($entity, $cacheability) as $name => $earlier) {
        if ($name === $fieldName) {
          break;
        }
        if ($this->firstRendered($earlier, $cacheability) !== NULL) {
          return FALSE;
        }
      }
    }
    $component = $item->getComponent($uuid);
    return $component !== NULL && !$this->declines($component->getComponentId());
  }

  /**
   * Whether a component's definition declines priority (`priority: false`).
   *
   * @param string $componentId
   *   The SDC id.
   */
  public function declines(string $componentId): bool {
    $definition = $this->componentPluginManager->getDefinition($componentId, FALSE);
    return is_array($definition) && ($definition['priority'] ?? NULL) === FALSE;
  }

  /**
   * Wraps a component's build in the priority grant.
   *
   * The grant opens before the build renders and is withdrawn after, so only
   * an image inside it can claim it.
   *
   * @param array $build
   *   The component's render array.
   *
   * @return array
   *   The wrapped render array.
   */
  public static function wrap(array $build): array {
    return [
      '#pre_render' => [[self::class, 'preRenderGrant']],
      '#post_render' => [[self::class, 'postRenderWithdraw']],
      'component' => $build,
    ];
  }

  /**
   * Opens the grant before the wrapped component renders.
   */
  public static function preRenderGrant(array $element): array {
    if (\Drupal::hasService(self::GRANT_SERVICE)) {
      \Drupal::service(self::GRANT_SERVICE)->grant();
    }
    return $element;
  }

  /**
   * Withdraws an unclaimed grant once the wrapped component has rendered.
   */
  public static function postRenderWithdraw(mixed $markup, array $element): mixed {
    if (\Drupal::hasService(self::GRANT_SERVICE)) {
      \Drupal::service(self::GRANT_SERVICE)->withdraw();
    }
    return $markup;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['preRenderGrant', 'postRenderWithdraw'];
  }

  /**
   * The first root component a tree renders, if any.
   *
   * The same filter the tree's render applies: published (or any, in a
   * draft) and viewable.
   */
  private function firstRendered(ComponentTreeItem $item, CacheableMetadata $cacheability): ?string {
    foreach ($item->getComponents(ComponentTreeStructure::ROOT_UUID) as $uuid => $component) {
      if (!$component->isPublished() && !$item->isDraft()) {
        continue;
      }
      $access = $component->access('view', NULL, TRUE);
      $cacheability->addCacheableDependency($access);
      if ($access->isAllowed()) {
        return (string) $uuid;
      }
    }
    return NULL;
  }

}
