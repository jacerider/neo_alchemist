<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\neo_alchemist\ComponentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_component_page_renderer'),
      $container->get('plugin.manager.sdc')
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(ComponentInterface $neo_component) {
    $build = [
      '#theme' => 'neo_alchemist_component_preview',
      '#attached' => [
        'library' => [
          'neo_alchemist/component.child',
        ],
      ],
    ];

    $neo_component->setPreview(TRUE);
    $build['component'] = $neo_component->toRenderable();

    return $this->bareHtmlPageRenderer->renderBarePage($build, 'Preview: ' . $neo_component->label(), 'page__neo_alchemist_preview')->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
  }

}
