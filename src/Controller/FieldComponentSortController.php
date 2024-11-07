<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class FieldComponentSortController extends ControllerBase {

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
  public function __invoke(Request $request, RouteMatchInterface $routeMatch) {
    $fieldDefinition = $this->getFieldDefinitionFromRouteMatch($routeMatch);
    $fieldItem = $fieldDefinition->getFieldItem();
    // $instance = $this->getFieldDefinitionFromRouteMatch($routeMatch)->getFieldItem()->getComponent($uuid);
    return $this->entityFormBuilder()->getForm($fieldItem->getEntity(), 'alchemist_sort', [
      'fieldItem' => $fieldItem,
      'uuid' => $request->query->get('uuid'),
    ]);
  }

  /**
   * Returns the title.
   */
  public function getTitle(RouteMatchInterface $routeMatch, string $field) {
    $fieldDefinition = $this->getFieldDefinitionFromRouteMatch($routeMatch);
    $entityType = $this->entityTypeManager()->getDefinition($fieldDefinition->getTargetEntityTypeId());
    return $this->t('Sort the components on %label: %field_label', [
      '%label' => $entityType->getLabel(),
      '%field_label' => $fieldDefinition->getLabel(),
    ]);
  }

}
