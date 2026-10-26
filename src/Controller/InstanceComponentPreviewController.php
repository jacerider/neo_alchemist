<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_alchemist\EditorState\EditorScratchStore;
use Drupal\neo_alchemist\EditorState\SharedDraftStore;
use Drupal\neo_alchemist\PageHeading;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_alchemist\PreviewPropMapBuilder;
use Drupal\neo_icon\IconTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentPreviewController extends ControllerBase {

  use IconTrait;

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer,
    private readonly EditorScratchStore $scratchStore,
    private readonly SharedDraftStore $sharedDraftStore,
    private readonly RouteMatchInterface $routeMatch,
    private readonly PageHeading $pageHeading,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_component_page_renderer'),
      $container->get('neo_alchemist.editor_scratch_store'),
      $container->get('neo_alchemist.shared_draft_store'),
      $container->get('current_route_match'),
      $container->get('neo_alchemist.page_heading'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(Request $request, ComponentTreeItem $neo_field) {
    // Render in preview mode.
    $neo_field->setPreview(TRUE);
    // Whether anything unsaved is in play. A preview that merely shows the
    // stored layout is the same for every request that can see it, so it is
    // ordinary cacheable output; only a draft makes it request-specific.
    $hasDraft = FALSE;
    if ($uuid = $request->query->get('uuid')) {
      // A single component's preview reflects that editor's live form buffer,
      // held in the per-user scratch store — not the shared whole-tree draft.
      $draft = $this->scratchStore->get($neo_field, $uuid);
      $hasDraft = !empty($draft);
      $build = $this->single($neo_field, $uuid, $request->query->get('component'), $draft);
      $draftCacheTag = $this->scratchStore->cacheTag($neo_field, $uuid);
    }
    else {
      // The component the inspector has open, if this render is for one: its
      // prop map comes with the page, so the canvas answers a click with the
      // field it belongs to. `live` is the form's own refresh: that editor's
      // unapplied values (its scratch buffer) go on top of the draft. Every
      // other render — opening, Cancel, a layout write — shows the draft, so
      // a buffer Cancel has not cleared yet never comes back.
      $inspect = (string) $request->query->get('inspect', '');
      $live = $inspect !== '' && $request->query->get('live');
      $inspected = $live && $neo_field->hasComponent($inspect)
        ? $this->scratchStore->get($neo_field, $inspect)
        : NULL;
      $build = $this->all($neo_field, $inspect !== '' && $neo_field->hasComponent($inspect) ? $inspect : NULL, $inspected);
      if ($request->query->get('size') === 'desktop') {
        neo_alchemist_attach_screenshot($build);
      }
      // The whole-layout preview reflects the shared draft — read through the
      // store, which owns both the existence check and the cache-tag string it
      // invalidates on write.
      $hasDraft = $this->sharedDraftStore->has($neo_field) || !empty($inspected);
      $draftCacheTag = $this->sharedDraftStore->cacheTag($neo_field);
      // The inspector's buffer changes this render too, and the render made
      // when the inspector opened (no buffer yet) is cacheable: without the
      // buffer's tag, Dynamic Page Cache hands that one back for every edit.
      if ($live) {
        $inspectCacheTag = $this->scratchStore->cacheTag($neo_field, $inspect);
      }
    }

    // Tag the response with the draft that would change it. Writing or
    // clearing a draft invalidates this, which is the only thing that can make
    // an already-cached preview re-run this controller — Dynamic Page Cache
    // serves a hit without ever calling us, so the $hasDraft branch below can
    // never demote an entry that already exists.
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags([$draftCacheTag]);
    if (isset($inspectCacheTag)) {
      $cacheability->addCacheTags([$inspectCacheTag]);
    }
    if ($hasDraft) {
      // The draft lives in tempstore/state, which carry no cache tag to
      // invalidate on, so an unsaved preview cannot be cached at all.
      $cacheability->setCacheMaxAge(0);
    }
    else {
      // Dynamic Page Cache keys on `route`, which ignores query arguments
      // entirely — so every argument that changes what this controller returns
      // has to be declared, or the first response rendered is handed to all of
      // them. All three of these do: `uuid` and `component` choose between the
      // whole-layout preview and one component's, and `size` selects the
      // screenshot library above. (`id` is deliberately absent: it only labels
      // the postMessage channel, is read from location.search in the browser,
      // and never reaches this controller — including it would fragment the
      // cache per editor container for no gain.)
      $cacheability->addCacheContexts([
        'url.query_args:uuid',
        'url.query_args:component',
        'url.query_args:size',
        'url.query_args:inspect',
        'url.query_args:live',
      ]);
    }

    return $this->bareHtmlPageRenderer
      ->renderBarePage($build, $this->getTitle($neo_field), 'front')
      ->addCacheableDependency($cacheability);
  }

  /**
   * Preview for a single component.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $neo_field
   *   The component field.
   * @param string $uuid
   *   The component UUID.
   * @param string $component
   *   The component name.
   * @param array|null $draft
   *   The unsaved draft for this component, if the editor has one open. Read
   *   by the caller, which needs to know whether one exists to decide the
   *   response's cacheability, and passed in rather than re-read here.
   *
   * @return array
   *   The render array.
   */
  protected function single(ComponentTreeItem $neo_field, string $uuid, string $component, ?array $draft = NULL) {
    if (!$neo_field->hasComponent($uuid)) {
      $neo_field->addComponent($uuid, $component);
    }

    if (!empty($draft)) {
      $neo_field->updateComponent($uuid, $draft);
    }

    $this->markPageContent($neo_field);
    $component = $neo_field->getComponent($uuid);
    $component->setInstancePreview(TRUE);
    $renderable = $component->toRenderable(routeMatch: $this->routeMatch);
    return [
      '#theme' => 'neo_alchemist_component_preview',
      '#attached' => [
        'library' => [
          'neo_alchemist/component.child',
        ],
        'drupalSettings' => [
          'neoAlchemist' => [
            'propMap' => PreviewPropMapBuilder::build($component, $renderable),
          ],
        ],
      ],
      'component' => $renderable,
    ];
  }

  /**
   * Marks a previewed tree as page content when its host's page renders it.
   *
   * So the preview prints the H1 the page will. A header or menu tree is
   * previewed through here too, and is not page content.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $neo_field
   *   The component field.
   */
  private function markPageContent(ComponentTreeItem $neo_field): void {
    $pageHeading = $this->pageHeading;
    $pageHeading->setPageContent($neo_field, $pageHeading->isPageField($neo_field));
  }

  /**
   * Preview for all components.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $neo_field
   *   The component field.
   * @param string|null $inspect
   *   The uuid of the component the inspector has open, if any.
   * @param array|null $inspected
   *   That component's unapplied values, if the inspector holds any.
   *
   * @return array
   *   The render array.
   */
  protected function all(ComponentTreeItem $neo_field, ?string $inspect = NULL, ?array $inspected = NULL) {
    $build = [
      '#attached' => [
        'library' => [
          'neo_alchemist/components.child',
        ],
      ],
    ];

    if ($inspect !== NULL && !empty($inspected)) {
      // Before anything loads the tree's instances: they are built from the
      // stored values once and kept, so an update after that would not reach
      // the one this renders.
      $neo_field->updateComponent($inspect, $inspected);
    }
    $this->markPageContent($neo_field);
    if ($inspect !== NULL) {
      // The tree renders the instance it hands out here, so flagging it
      // stamps the prop markers component-child.ts matches the form against,
      // as the single-component preview does, while it stays a layout render.
      $component = $neo_field->getComponent($inspect);
      $component?->setInspected(TRUE);
    }
    $build['components'] = $neo_field->toRenderable();
    if ($inspect !== NULL && isset($component)) {
      $build['#attached']['drupalSettings']['neoAlchemist']['propMap'] = PreviewPropMapBuilder::build($component, $component->toRenderable(routeMatch: $this->routeMatch));
    }
    $build['components']['#theme'] = 'neo_alchemist_component_preview';

    if (!$neo_field->getComponents()) {
      $build['components']['empty'] = [
        '#type' => 'inline_template',
        '#template' => '<div class="text-xs p-3 bg-base-100 text-base-700 border border-dashed text-center">{{ icon("info-circle") }} {{ empty_message }}</div>',
        '#context' => [
          'empty_message' => $this->t('No components have been added to this layout. Use the <strong>@add</strong> button in the footer to add a component.', [
            '@add' => $this->icon('Add', 'plus'),
          ]),
        ],
      ];
    }
    return $build;
  }

  /**
   * Builds the title.
   */
  public function getTitle(ComponentTreeItem $neo_field) {
    $label = $neo_field->belongsToFieldConfig() ? $neo_field->getEntity()->getEntityType()->getLabel() : $neo_field->getEntity()->label();
    return $this->t('Preview for %label: %field_label', [
      '%label' => $label,
      '%field_label' => $neo_field->getFieldDefinition()->getLabel(),
    ]);
  }

}
