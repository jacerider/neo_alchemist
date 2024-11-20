<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class FieldComponentAddController extends ControllerBase {

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
  public function __invoke(RouteMatchInterface $routeMatch, ComponentInterface $neo_component) {
    $instance = $this->getFieldDefinitionFromRouteMatch($routeMatch)->getFieldItem()->createComponent($neo_component);
    return $this->entityFormBuilder()->getForm($instance->getTargetEntity(), 'alchemist', [
      'neo_component_instance' => $instance,
    ]);
  }

  /**
   * Builds the title.
   */
  public function getTitle(RouteMatchInterface $routeMatch, ComponentInterface $neo_component) {
    $fieldDefinition = $this->getFieldDefinitionFromRouteMatch($routeMatch);
    $entityType = $this->entityTypeManager()->getDefinition($fieldDefinition->getTargetEntityTypeId());
    return $this->t('Add %component to %label: %field_label', [
      '%component' => $neo_component->label(),
      '%label' => $entityType->getLabel(),
      '%field_label' => $fieldDefinition->getLabel(),
    ]);
  }

}
