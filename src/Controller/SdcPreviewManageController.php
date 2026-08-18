<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\Url;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\ComponentPreviewBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders the editable preview workspace for a raw SDC.
 *
 * This mirrors the saved-component manage UI (@see ComponentManageController)
 * but operates on a transient (unsaved) neo_component entity built from the
 * SDC's `.component.yml`. The component itself renders inside an isolated
 * iframe (the bare neo_alchemist.sdc_preview_frame route) while a form lets a
 * developer edit prop and style values, refreshing the preview live without
 * saving anything to configuration.
 */
final class SdcPreviewManageController extends ControllerBase {

  public function __construct(
    private readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer,
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly ComponentPreviewBuilder $previewBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_component_page_renderer'),
      $container->get('plugin.manager.sdc'),
      $container->get('neo_alchemist.preview_builder'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(string $component) {
    $entity = $this->buildEntity($component);

    $build = [
      '#theme' => 'neo_alchemist_manage',
      '#id' => ComponentManageHelper::getId($entity),
      '#iframe_url' => Url::fromRoute('neo_alchemist.sdc_preview_frame', ['component' => $component]),
      '#attached' => [
        'library' => [
          'neo_alchemist/component.parent',
        ],
      ],
      '#form' => $this->entityFormBuilder()->getForm($entity, 'preview_value'),
    ];

    $build['#top_start']['back'] = [
      '#type' => 'link',
      '#title' => neo_icon_admin($this->t('Back'), 'arrow-circle-left'),
      '#url' => Url::fromRoute('neo_alchemist.sdc_preview_list'),
      '#attributes' => [
        'class' => ['btn', 'btn-xs'],
      ],
    ];

    // Neighbor component selector (render a component above and/or below the
    // previewed one to test spacing between stacked components).
    $build['#top_start']['context'] = $this->entityFormBuilder()->getForm($entity, 'preview_context');

    // Resize / scale operations.
    $build['#top_end'] = ComponentManageHelper::buildIframeOperations($entity);

    $title = 'Preview: ' . $entity->label();
    return $this->bareHtmlPageRenderer
      // The page renderer ignores its $title argument, so set the page title
      // via the page additions where template_preprocess_html() reads it for
      // the document <title>.
      ->renderBarePage($build, $title, 'back', ['#title' => $title])
      ->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
  }

  /**
   * Returns the title.
   */
  public function getTitle(string $component) {
    $definition = $this->componentPluginManager->hasDefinition($component)
      ? $this->componentPluginManager->getDefinition($component)
      : NULL;
    return $definition['name'] ?? $component;
  }

  /**
   * Builds the transient component entity for the given SDC.
   *
   * Delegates so the deterministic id has one owner. That id is what links the
   * preview-value cache entries this form writes to the ones the iframe render
   * reads back, and a second copy of the derivation could drift the two apart.
   *
   * @param string $component
   *   The SDC component id (e.g. "front:accordion_test").
   *
   * @return \Drupal\neo_alchemist\ComponentInterface
   *   The transient component entity.
   */
  private function buildEntity(string $component) {
    $entity = $this->previewBuilder->build($component);
    if (!$entity) {
      throw new NotFoundHttpException();
    }
    return $entity;
  }

}
