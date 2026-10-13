<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_alchemist\Audit\ComponentAudit;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The component audit for the component preview page's badge.
 *
 * Rendered under the same route options as the preview frame, so it audits
 * what the frame shows: the values being edited in the preview form.
 */
final class SdcPreviewAuditController extends ControllerBase {

  public function __construct(
    private readonly ComponentAudit $audit,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_alchemist.component_audit'),
      $container->get('current_route_match'),
    );
  }

  /**
   * The issues, as JSON.
   */
  public function __invoke(string $component): CacheableJsonResponse {
    $issues = $this->audit->audit($component, $this->routeMatch);
    if ($issues === NULL) {
      throw new NotFoundHttpException();
    }
    $response = new CacheableJsonResponse(['issues' => $issues]);
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
    return $response;
  }

}
