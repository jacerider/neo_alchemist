<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\neo_alchemist\Plugin\ComponentShape\HeadingShape;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapeRegionPluginInterface;

/**
 * Decides which heading, if any, is the page's H1.
 *
 * A page has one H1. On a page whose content is an Alchemist layout it is the
 * first heading of that layout, in reading order, that can be one: it has
 * text, its prop does not opt out (`h1: false`), and its component's template
 * prints the tag it is handed (`heading.tag`). Every other heading renders its
 * fallback — h2 unless the prop declares another. When no heading qualifies,
 * the page keeps the page title block's (visually hidden) H1, and when one
 * does, that block is not printed.
 *
 * Only the layout that is the page's main content takes part: the trees an
 * entity's full view mode renders, which the formatter marks. The header, mega
 * menu panels and entities embedded in a slot are rendered on their own and
 * never do. When the full view mode renders several trees, they are read in
 * the display's order, and only the first with a qualifying heading has one.
 *
 * The decision is made from the trees before rendering, in one walk per tree,
 * so it travels with the trees' own render cache and the title block can ask
 * the same question without anything having rendered.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentShape\HeadingShape::preRenderValue()
 * @see neo_alchemist_block_view_page_title_block_alter()
 */
final class PageHeading {

  /**
   * The view mode whose trees are a page's main content.
   */
  private const VIEW_MODE = 'full';

  /**
   * Whether a tree item renders as its page's main content.
   *
   * @var \WeakMap<\Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem, bool>
   */
  private \WeakMap $pageContent;

  /**
   * Each walked tree's first qualifying heading, and the walk's metadata.
   *
   * @var \WeakMap<\Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem, array{0: ?string, 1: \Drupal\Core\Cache\CacheableMetadata}>
   */
  private \WeakMap $walks;

  /**
   * The first qualifying heading found by the walk in progress.
   */
  private ?string $found = NULL;

  /**
   * Whether a walk is in progress.
   */
  private bool $walking = FALSE;

  /**
   * Whether each component template prints a heading's tag.
   *
   * @var array<string, bool>
   */
  private array $printsTag = [];

  public function __construct(
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly RendererInterface $renderer,
  ) {
    $this->pageContent = new \WeakMap();
    $this->walks = new \WeakMap();
  }

  /**
   * Marks whether a tree renders as its page's main content.
   *
   * Page content is walked for its H1 here, before any of it renders, so no
   * heading has to start the walk part way through rendering the tree.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The tree.
   * @param bool $isPageContent
   *   TRUE when it is the page's main content.
   */
  public function setPageContent(ComponentTreeItem $item, bool $isPageContent = TRUE): void {
    $this->pageContent[$item] = $isPageContent;
    if ($isPageContent) {
      $this->claim($item);
    }
  }

  /**
   * Whether a tree renders as its page's main content.
   */
  public function isPageContent(ComponentTreeItem $item): bool {
    return $this->pageContent[$item] ?? FALSE;
  }

  /**
   * Whether a tree's field is one its host's full page renders.
   *
   * For previews, which render a tree directly rather than through the
   * entity's display: a tree is previewed as page content when the host's
   * full view mode renders it, so the preview shows the H1 the page will.
   */
  public function isPageField(ComponentTreeItem $item): bool {
    $entity = $item->getEntity();
    if (!$entity instanceof ContentEntityInterface) {
      return FALSE;
    }
    return in_array($item->getFieldDefinition()->getName(), $this->pageFields($entity), TRUE);
  }

  /**
   * Whether a heading on the current route's page is its H1.
   *
   * The route's entity, in the view mode the route renders it in — the same
   * display neo_alchemist_route_is_alchemist() consults. A route rendering any
   * other view mode than the full one has no page content.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The route.
   *
   * @return array{0: bool, 1: \Drupal\Core\Cache\CacheableMetadata}
   *   Whether a heading is the page's H1, and what that depends on.
   */
  public function routeHasH1(RouteMatchInterface $routeMatch): array {
    $cacheability = new CacheableMetadata();
    $route = $routeMatch->getRouteObject();
    $entityTypeId = $route?->getOption('_neo_alchemist');
    $entity = $entityTypeId ? $routeMatch->getParameter($entityTypeId) : NULL;
    if (!$entity instanceof ContentEntityInterface) {
      return [FALSE, $cacheability];
    }
    $entityView = (string) ($route->getDefault('_entity_view') ?? $entityTypeId . '.' . self::VIEW_MODE);
    if ((explode('.', $entityView)[1] ?? NULL) !== self::VIEW_MODE) {
      return [FALSE, $cacheability];
    }
    foreach ($this->pageItems($entity, $cacheability) as $item) {
      $this->setPageContent($item);
      [$found, $walked] = $this->walk($item);
      $cacheability->addCacheableDependency($walked);
      if ($found !== NULL) {
        return [TRUE, $cacheability];
      }
    }
    return [FALSE, $cacheability];
  }

  /**
   * The heading that is the page's H1, if it is in this tree.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The tree.
   *
   * @return string|null
   *   The heading's key (see ::key()), or NULL when no heading in the tree is
   *   the page's H1: none qualifies, a tree before it on the page has it, or
   *   the tree is not page content.
   */
  public function claim(ComponentTreeItem $item): ?string {
    if (!$this->isPageContent($item)) {
      return NULL;
    }
    $entity = $item->getEntity();
    $fieldName = $item->getFieldDefinition()->getName();
    if ($entity instanceof ContentEntityInterface) {
      foreach ($this->pageItems($entity) as $name => $earlier) {
        if ($name === $fieldName) {
          break;
        }
        $this->pageContent[$earlier] = TRUE;
        if ($this->walk($earlier)[0] !== NULL) {
          return NULL;
        }
      }
    }
    return $this->walk($item)[0];
  }

  /**
   * The tag a heading renders.
   *
   * Asked by every heading with text as it renders. During a walk, a heading
   * that could be the H1 reports itself instead, and the first to report is
   * the tree's.
   *
   * @param \Drupal\neo_alchemist\Plugin\ComponentShape\HeadingShape $shape
   *   The heading.
   *
   * @return string
   *   `h1`, or the heading's fallback.
   */
  public function tag(HeadingShape $shape): string {
    $fallback = HeadingTag::fallback($shape->getSchema());
    $component = $shape->getComponent();
    if (!$component instanceof ComponentInstanceInterface) {
      return $fallback;
    }
    if ($this->walking) {
      if ($this->found === NULL && $this->qualifies($shape)) {
        $this->found = $this->key($shape);
      }
      return $fallback;
    }
    return $this->claim($component->getFieldItem()) === $this->key($shape) ? 'h1' : $fallback;
  }

  /**
   * The trees an entity's full view mode renders, in the display's order.
   *
   * A tree the visitor may not view is left out, as the display leaves it out
   * — a term's per-level layouts hide all but their own level's this way.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Collects what the field access depends on.
   *
   * @return \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem[]
   *   The trees that hold components, keyed by field name.
   */
  private function pageItems(ContentEntityInterface $entity, ?CacheableMetadata $cacheability = NULL): array {
    $items = [];
    foreach ($this->pageFields($entity) as $fieldName) {
      if (!$entity->hasField($fieldName)) {
        continue;
      }
      $list = $entity->get($fieldName);
      $access = $list->access('view', NULL, TRUE);
      $cacheability?->addCacheableDependency($access);
      $item = $list->first();
      if ($access->isAllowed() && $item instanceof ComponentTreeItem) {
        $items[$fieldName] = $item;
      }
    }
    return $items;
  }

  /**
   * The tree fields an entity's full view mode renders, in display order.
   *
   * @return string[]
   *   Field names.
   */
  private function pageFields(ContentEntityInterface $entity): array {
    $components = EntityViewDisplay::collectRenderDisplay($entity, self::VIEW_MODE)->getComponents();
    $components = array_filter($components, fn(array $component) => ($component['type'] ?? NULL) === 'neo_component_tree');
    uasort($components, fn(array $a, array $b) => ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0));
    return array_map('strval', array_keys($components));
  }

  /**
   * Walks a tree, once, for its first qualifying heading.
   *
   * Reading order: components in tree order, a component's own headings
   * before its children's, an array's items in order. Only what renders is
   * walked — published components (or any, in a draft), the visitor may see,
   * under a parent that renders — and of each component, only the props that
   * hold a heading. Those resolve exactly as they will render, so a heading
   * whose text comes out empty is passed over here just as it renders nothing
   * there. They resolve in a render context of their own, as a value provider
   * may render while it resolves.
   *
   * @return array{0: ?string, 1: \Drupal\Core\Cache\CacheableMetadata}
   *   The qualifying heading's key, and the walk's cacheability.
   */
  private function walk(ComponentTreeItem $item): array {
    if (isset($this->walks[$item])) {
      return $this->walks[$item];
    }
    $cacheability = new CacheableMetadata();
    if ($this->walking) {
      // A tree reached while another is walked (nothing does today) does not
      // take part rather than start a second walk part way through.
      return [NULL, $cacheability];
    }
    $this->walking = TRUE;
    $this->found = NULL;
    $context = new RenderContext();
    try {
      $this->renderer->executeInRenderContext($context, fn() => $this->walkChildren($item, ComponentTreeStructure::ROOT_UUID, $cacheability, 0));
    }
    finally {
      $this->walking = FALSE;
    }
    if (!$context->isEmpty()) {
      $cacheability->addCacheableDependency($context->pop());
    }
    $cacheability->addCacheableDependency($item->getEntity());
    return $this->walks[$item] = [$this->found, $cacheability];
  }

  /**
   * Walks one parent's components until a heading is found.
   */
  private function walkChildren(ComponentTreeItem $item, string $parentUuid, CacheableMetadata $cacheability, int $depth): void {
    if ($depth > 20) {
      return;
    }
    foreach ($item->getComponents($parentUuid) as $uuid => $component) {
      if (!$component->isPublished() && !$item->isDraft()) {
        continue;
      }
      $access = $component->access('view', NULL, TRUE);
      $cacheability->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }
      foreach ($component->getPropShapes() as $shape) {
        if ($shape->isActive() && $this->holdsHeading($shape)) {
          $shape->getPropValue(new Attribute());
          $cacheability->addCacheableDependency($shape->getCacheableMetadata());
          if ($this->found !== NULL) {
            return;
          }
        }
      }
      $this->walkChildren($item, (string) $uuid, $cacheability, $depth + 1);
      if ($this->found !== NULL) {
        return;
      }
    }
  }

  /**
   * Whether a prop holds a heading, and is not a region.
   *
   * A region's value is its child components, rendered; they are walked as
   * the tree's children instead.
   */
  private function holdsHeading(ComponentShapePluginInterface $shape): bool {
    $heading = FALSE;
    foreach ($shape->getAllShapes(TRUE) as $child) {
      if ($child instanceof ComponentShapeRegionPluginInterface) {
        return FALSE;
      }
      $heading = $heading || $child instanceof HeadingShape;
    }
    return $heading;
  }

  /**
   * Whether a heading with text may be the page's H1.
   */
  private function qualifies(HeadingShape $shape): bool {
    if (!HeadingTag::allowsH1($shape->getSchema())) {
      return FALSE;
    }
    $componentId = $shape->getComponent()->getComponentId();
    $key = $componentId . ':' . $shape->getName();
    if (!isset($this->printsTag[$key])) {
      $template = $this->componentPluginManager->find($componentId)->getTemplatePath();
      $twig = $template && is_file($template) ? (string) file_get_contents($template) : '';
      $this->printsTag[$key] = HeadingTag::printsTag($twig, $shape->getName());
    }
    return $this->printsTag[$key];
  }

  /**
   * A heading's key: its component and its place in the component's props.
   */
  private function key(HeadingShape $shape): string {
    return $shape->getComponent()->uuid() . '|' . $shape->id();
  }

}
