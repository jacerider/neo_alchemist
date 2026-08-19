<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\neo_alchemist\ComponentPreviewBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders a preview of an SDC without requiring a component entity.
 *
 * A transient (unsaved) neo_component entity is constructed on the fly so that
 * the Alchemist shape/slot pipeline produces default values from the SDC's
 * `.component.yml` `examples`.
 */
final class SdcPreviewController extends ControllerBase {

  public function __construct(
    private readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer,
    private readonly ComponentPreviewBuilder $previewBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_component_page_renderer'),
      $container->get('neo_alchemist.preview_builder'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(Request $request, string $component) {
    $entity = $this->previewBuilder->build($component);
    if (!$entity) {
      throw new NotFoundHttpException();
    }

    $build = [
      '#theme' => 'neo_alchemist_component_preview',
      '#attached' => [
        'library' => [
          'neo_alchemist/component.child',
        ],
      ],
    ];

    // Render the chosen neighbor components above and/or below the previewed
    // one (as adjacent siblings) so spacing/collapse behavior can be tested.
    // The preview template emits children in insertion order, so add them top
    // to bottom: above, main, below.
    $context = $entity->getPreviewContext();
    if (!empty($context['above']) && ($above = $this->previewBuilder->build($context['above']))) {
      $build['component_above'] = $above->toRenderable();
    }
    $build['component'] = $entity->toRenderable();
    if (!empty($context['below']) && ($below = $this->previewBuilder->build($context['below']))) {
      $build['component_below'] = $below->toRenderable();
    }

    $size = $request->query->get('size');
    if ($size === 'desktop') {
      neo_alchemist_attach_screenshot($build);
    }

    return $this->bareHtmlPageRenderer
      ->renderBarePage($build, 'Preview: ' . $entity->label(), 'front')
      ->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
  }

}
