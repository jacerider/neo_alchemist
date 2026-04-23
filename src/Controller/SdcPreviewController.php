<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
    private readonly ComponentPluginManager $componentPluginManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_component_page_renderer'),
      $container->get('plugin.manager.sdc'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(string $component) {
    $definition = $this->componentPluginManager->hasDefinition($component)
      ? $this->componentPluginManager->getDefinition($component)
      : NULL;
    if (!$definition || empty($definition['neo'])) {
      throw new NotFoundHttpException();
    }

    /** @var \Drupal\neo_alchemist\ComponentInterface $entity */
    $entity = $this->entityTypeManager()->getStorage('neo_component')->create([
      'id' => 'sdc_preview_' . hash('crc32b', $component),
      'label' => $definition['name'] ?? $component,
      'component' => $component,
      'status' => TRUE,
    ]);
    $entity->setPreview(TRUE);

    $build = [
      '#theme' => 'neo_alchemist_component_preview',
      '#attached' => [
        'library' => [
          'neo_alchemist/component.child',
        ],
      ],
      'component' => $entity->toRenderable(),
    ];

    $size = \Drupal::request()->query->get('size');
    if ($size === 'desktop') {
      $build['#attached']['library'][] = 'neo_alchemist/component.screenshot';
    }

    return $this->bareHtmlPageRenderer
      ->renderBarePage($build, 'Preview: ' . $entity->label(), 'front')
      ->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
  }

}
