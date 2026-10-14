<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RequestContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;

/**
 * What `drush neo:alchemist:h1-report` checks, and how it checks a page.
 *
 * Which pages: an Alchemist page is an entity whose full view mode renders a
 * component tree. Where a page can have a layout of its own — a custom
 * layout, or a locked one with editable regions (hybrid) — every page is
 * checked, as each can differ. Where the layout is locked, its pages share it
 * and differ only in their data, so a sample stands for them: the most
 * recently changed. `all` checks every page either way.
 *
 * How: the entity is rendered in its full view mode, as its page renders it
 * but without the page around it — no header, menus, footer or blocks — under
 * a request for its page, so a value read from the page (its title, its
 * route) is the page's. The H1s are read off that markup, each with the
 * component it sits in. When no heading in the layout is the page's H1, the
 * page title is (kept visually hidden), and is counted as one. An `<h1>` a
 * block or the theme prints outside the layout is not seen.
 */
class PageHeadingReport {

  /**
   * The view mode a page renders, as PageHeading reads it.
   */
  protected const VIEW_MODE = 'full';

  /**
   * Constructs the report.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected RendererInterface $renderer,
    protected RequestStack $requestStack,
    protected RequestMatcherInterface $router,
    protected RequestContext $requestContext,
    protected PageHeading $pageHeading,
  ) {}

  /**
   * The pages to check, a group per bundle.
   *
   * @param string|null $entityTypeId
   *   Only this entity type.
   * @param string|null $bundle
   *   Only this bundle.
   * @param bool $all
   *   Every page, sampling nothing.
   * @param int $sample
   *   How many pages of a locked layout stand for the rest.
   * @param int|null $limit
   *   At most this many pages of any bundle.
   *
   * @return array<int, array{entity_type: string, bundle: string, layout: 'custom'|'hybrid'|'locked', ids: array<int|string>, total: int, sampled: bool}>
   *   Each bundle with an Alchemist page: how its layout is set, the ids to
   *   check, how many published pages it has, and whether the ids are a
   *   sample of them.
   */
  public function plan(?string $entityTypeId = NULL, ?string $bundle = NULL, bool $all = FALSE, int $sample = 5, ?int $limit = NULL): array {
    $groups = [];
    foreach ($this->bundles($entityTypeId, $bundle) as [$type, $name, $layout]) {
      $definition = $this->entityTypeManager->getDefinition($type);
      $query = fn() => $this->query($type, $name);
      $total = (int) $query()->count()->execute();
      if (!$total) {
        continue;
      }
      $sampled = $layout === 'locked' && !$all;
      $ids = $query();
      $count = $limit;
      if ($sampled) {
        // The pages edited most recently: their data is how editors fill
        // the layout today.
        $changed = isset($this->entityFieldManager->getBaseFieldDefinitions($type)['changed']);
        $ids->sort($changed ? 'changed' : $definition->getKey('id'), 'DESC');
        $count = min(max(1, $sample), $limit ?: PHP_INT_MAX);
      }
      else {
        $ids->sort($definition->getKey('id'));
      }
      if ($count) {
        $ids->range(0, $count);
      }
      $ids = array_values($ids->execute());
      $groups[] = [
        'entity_type' => $type,
        'bundle' => $name,
        'layout' => $layout,
        'ids' => $ids,
        'total' => $total,
        'sampled' => $sampled && count($ids) < $total,
      ];
    }
    return $groups;
  }

  /**
   * One page's row: its H1s and where each comes from.
   *
   * @return array{path: string, h1: string, source: string, count: string, status: string}
   *   The page's path, its H1s' text and sources (each a component id, or
   *   "page title"), how many, and "ok" or what went wrong.
   */
  public function row(ContentEntityInterface $entity): array {
    $path = $entity->toUrl()->toString(TRUE)->getGeneratedUrl();
    $texts = [];
    $sources = [];
    $status = 'ok';
    try {
      [$html, $claimed] = $this->render($entity, $path);
      $document = Html::load($html);
      foreach ($document->getElementsByTagName('h1') as $h1) {
        $texts[] = trim((string) preg_replace('/\s+/u', ' ', $h1->textContent));
        $source = 'entity display';
        for ($node = $h1->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
          if ($node->hasAttribute('data-component-id')) {
            $source = $node->getAttribute('data-component-id');
            break;
          }
        }
        $sources[] = $source;
      }
      // No heading in the layout is the H1: the page title is, printed above
      // the layout (visually hidden on an Alchemist page).
      if (!$claimed) {
        array_unshift($texts, (string) $entity->label());
        array_unshift($sources, 'page title');
      }
    }
    catch (\Throwable $e) {
      $status = 'error: ' . $e->getMessage();
    }
    return [
      'path' => $path,
      'h1' => implode(' | ', $texts),
      'source' => implode(' | ', $sources),
      'count' => (string) count($texts),
      'status' => $status,
    ];
  }

  /**
   * Renders an entity's full view mode under a request for its page.
   *
   * @return array{0: string, 1: bool}
   *   The markup, and whether a heading in it is the page's H1.
   */
  protected function render(ContentEntityInterface $entity, string $path): array {
    $request = Request::create($path);
    // A page may read the session (a view's exposed form, a search); the one
    // the run already has, or an empty one.
    $current = $this->requestStack->getCurrentRequest();
    $request->setSession($current?->hasSession() ? $current->getSession() : new Session(new MockArraySessionStorage()));
    $request->attributes->add($this->router->matchRequest($request));
    $this->requestStack->push($request);
    $this->requestContext->fromRequest($request);
    try {
      $build = $this->entityTypeManager->getViewBuilder($entity->getEntityTypeId())->view($entity, static::VIEW_MODE);
      $html = (string) $this->renderer->renderInIsolation($build);
      [$claim] = $this->pageHeading->entityH1($entity);
      return [$html, $claim !== NULL];
    }
    finally {
      $this->requestStack->pop();
      $this->requestContext->fromRequest($this->requestStack->getCurrentRequest() ?? Request::create('/'));
    }
  }

  /**
   * The published pages of a bundle, as a query.
   */
  protected function query(string $entityTypeId, string $bundle) {
    $definition = $this->entityTypeManager->getDefinition($entityTypeId);
    $query = $this->entityTypeManager->getStorage($entityTypeId)->getQuery()->accessCheck(FALSE);
    if ($bundleKey = $definition->getKey('bundle')) {
      $query->condition($bundleKey, $bundle);
    }
    if ($publishedKey = $definition->getKey('published')) {
      $query->condition($publishedKey, 1);
    }
    return $query;
  }

  /**
   * The bundles whose full view mode renders a tree, and how it is laid out.
   *
   * A bundle is "custom" or "hybrid" when any tree its page renders lets a
   * page have a layout (or regions) of its own, else "locked".
   *
   * @return array<int, array{0: string, 1: string, 2: string}>
   *   Entity type id, bundle, layout.
   */
  protected function bundles(?string $entityTypeFilter, ?string $bundleFilter): array {
    $bundles = [];
    $displays = $this->entityTypeManager->getStorage('entity_view_display');
    foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $definition) {
      if (!$definition->entityClassImplements(ContentEntityInterface::class) || !$definition->hasLinkTemplate('canonical')) {
        continue;
      }
      if ($entityTypeFilter && $entityTypeId !== $entityTypeFilter) {
        continue;
      }
      foreach (array_keys($this->bundleInfo->getBundleInfo($entityTypeId)) as $bundle) {
        if ($bundleFilter && $bundle !== $bundleFilter) {
          continue;
        }
        // The full view mode, or the default display it falls back to.
        $display = $displays->load("$entityTypeId.$bundle." . static::VIEW_MODE);
        if (!$display || !$display->status()) {
          $display = $displays->load("$entityTypeId.$bundle.default");
        }
        $fields = $this->entityFieldManager->getFieldDefinitions($entityTypeId, (string) $bundle);
        $layout = NULL;
        foreach ($display?->getComponents() ?? [] as $name => $component) {
          if (($component['type'] ?? NULL) !== 'neo_component_tree') {
            continue;
          }
          $field = $fields[$name] ?? NULL;
          $mode = match (TRUE) {
            $field instanceof ComponentFieldConfigInterface && $field->allowCustom() => 'custom',
            $field instanceof ComponentFieldConfigInterface && $field->isHybrid() => 'hybrid',
            default => 'locked',
          };
          // The most open tree decides: one page-owned tree makes every page
          // its own.
          $layout = match (TRUE) {
            $layout === 'custom' || $mode === 'custom' => 'custom',
            $layout === 'hybrid' || $mode === 'hybrid' => 'hybrid',
            default => 'locked',
          };
        }
        if ($layout !== NULL) {
          $bundles[] = [$entityTypeId, (string) $bundle, $layout];
        }
      }
    }
    return $bundles;
  }

}
