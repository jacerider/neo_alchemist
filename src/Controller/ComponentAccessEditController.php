<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\ComponentAccessFactory;
use Drupal\neo_alchemist\ComponentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class ComponentAccessEditController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly ComponentAccessFactory $accessFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_component.access.factory'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(ComponentInterface $neo_component, string $uuid): array {
    $access = $neo_component->getAccess($uuid);
    if (!$access) {
      throw new AccessDeniedHttpException();
    }
    return $this->entityFormBuilder()->getForm($neo_component, 'access', [
      'access' => $access,
    ]);
  }

}
