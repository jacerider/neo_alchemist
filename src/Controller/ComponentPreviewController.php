<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\neo_alchemist\ComponentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class ComponentPreviewController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer,
    private readonly ComponentPluginManager $componentPluginManager,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_component_page_renderer'),
      $container->get('plugin.manager.sdc'),
      $container->get('current_route_match')
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(Request $request, ComponentInterface $neo_component) {
    $build = [
      '#theme' => 'neo_alchemist_component_preview',
      '#attached' => [
        'library' => [
          'neo_alchemist/component.child',
        ],
      ],
    ];

    $size = $request->query->get('size');
    if ($size === 'desktop') {
      neo_alchemist_attach_screenshot($build);
    }

    $neo_component->setPreview(TRUE);
    $build['component'] = $neo_component->toRenderable(routeMatch: $this->routeMatch);

    return $this->bareHtmlPageRenderer->renderBarePage($build, 'Preview: ' . $neo_component->label(), 'front')->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
  }

}
