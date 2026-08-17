<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
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
   * Private temporary storage.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $store;

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer,
    PrivateTempStoreFactory $temp_store_factory,
  ) {
    $this->store = $temp_store_factory->get('neo_alchemist');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_component_page_renderer'),
      $container->get('tempstore.private')
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
      $draft = $this->store->get($neo_field->getDraftKey($uuid));
      $hasDraft = !empty($draft);
      $build = $this->single($neo_field, $uuid, $request->query->get('component'), $draft);
    }
    else {
      $build = $this->all($neo_field);
      if ($request->query->get('size') === 'desktop') {
        $build['#attached']['library'][] = 'neo_alchemist/component.screenshot';
      }
      $hasDraft = $neo_field->hasDraft();
    }

    // Tag the response with the draft that would change it. Writing or
    // clearing a draft invalidates this, which is the only thing that can make
    // an already-cached preview re-run this controller — Dynamic Page Cache
    // serves a hit without ever calling us, so the $hasDraft branch below can
    // never demote an entry that already exists.
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags([$neo_field->getDraftCacheTag($uuid ?: NULL)]);
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

    $component = $neo_field->getComponent($uuid);
    $component->setInstancePreview(TRUE);
    $renderable = $component->toRenderable();
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
   * Preview for all components.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $neo_field
   *   The component field.
   *
   * @return array
   *   The render array.
   */
  protected function all(ComponentTreeItem $neo_field) {
    $build = [
      '#attached' => [
        'library' => [
          'neo_alchemist/components.child',
        ],
      ],
    ];

    $build['components'] = $neo_field->toRenderable();
    $build['components']['#theme'] = 'neo_alchemist_component_preview';

    if (!$neo_field->getComponents()) {
      $build['components']['empty'] = [
        '#type' => 'inline_template',
        '#template' => '<div class="text-xs p-3 bg-base-100 text-base-700 border border-dashed text-center">{{ icon("info-circle") }}{{ empty_message }}</div>',
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
