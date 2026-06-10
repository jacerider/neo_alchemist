<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentManageHelper;
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
    $build = [
      '#theme' => 'neo_alchemist_manage',
      '#id' => ComponentManageHelper::getId($neo_component),
      '#iframe_url' => $neo_component->toUrl('preview'),
      '#attached' => [
        'library' => [
          'neo_alchemist/component.parent',
        ],
      ],
      '#form' => $this->entityFormBuilder()->getForm($neo_component, 'manage'),
    ];

    $build['#top_start']['back'] = [
      '#type' => 'link',
      '#title' => neo_icon_admin(t('Back'), 'arrow-circle-left'),
      '#url' => $neo_component->toUrl('collection'),
      '#attributes' => [
        'class' => ['btn', 'btn-xs'],
      ],
    ];

    $build['#top_start']['style'] = $this->entityFormBuilder()->getForm($neo_component, 'style');

    // Resize.
    $build['#top_end'] = ComponentManageHelper::buildIframeOperations($neo_component);

    $title = 'Manage: ' . $neo_component->label();
    // The page renderer ignores its $title argument, so set the page title via
    // the page additions where template_preprocess_html() reads it for the
    // document <title>.
    return $this->bareHtmlPageRenderer->renderBarePage($build, $title, 'back', ['#title' => $title]);
  }

  /**
   * Returns the title.
   */
  public function getTitle(ComponentInterface $neo_component) {
    return $neo_component->label();
  }

}
