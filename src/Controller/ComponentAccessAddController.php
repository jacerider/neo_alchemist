<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\ComponentAccessFactory;
use Drupal\neo_alchemist\ComponentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class ComponentAccessAddController extends ControllerBase {

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
  public function __invoke(ComponentInterface $neo_component): array {
    $access = $this->accessFactory->get($neo_component);
    return $this->entityFormBuilder()->getForm($neo_component, 'access', [
      'access' => $access,
    ]);
  }

}
