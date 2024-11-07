<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class FieldComponentDeleteController extends ControllerBase {

  use FieldComponentControllerTrait;

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_field.manager'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(RouteMatchInterface $routeMatch, string $uuid) {
    $instance = $this->getFieldDefinitionFromRouteMatch($routeMatch)->getFieldItem()->getComponent($uuid);
    return $this->entityFormBuilder()->getForm($instance->getEntity(), 'alchemist_delete', [
      'neo_component_instance' => $instance,
    ]);
  }

}
