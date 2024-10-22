<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class ComponentManageController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_component_page_renderer'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(ComponentInterface $neo_component) {
    $build = [];
    // $build['bla'] = $widget->;

    $build['form'] = $this->entityFormBuilder()->getForm($neo_component, 'manage');
    // $build['iframe'] = [
    //   '#type' => 'html_tag',
    //   '#tag' => 'iframe',
    //   '#attributes' => [
    //     'src' => $neo_component->toUrl('preview')->toString(),
    //     'width' => '100%',
    //     'height' => '800px',
    //     'frameborder' => '0',
    //     'class' => [
    //       'border-2',
    //     ],
    //   ],
    // ];

    // return $build;

    return $this->bareHtmlPageRenderer->renderBarePage($build, 'Preview: ' . $neo_component->label(), 'neo_component_preview', [
      '#show_messages' => TRUE,
    ]);
  }

}
