<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_alchemist\ComponentFilterFactory;
use Drupal\neo_alchemist\ComponentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class ComponentFilterAddController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly ComponentFilterFactory $filterFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_component.filter.factory'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(ComponentInterface $neo_component): array {
    $filter = $this->filterFactory->get($neo_component);
    return $this->entityFormBuilder()->getForm($neo_component, 'filter', [
      'filter' => $filter,
    ]);
  }

}
