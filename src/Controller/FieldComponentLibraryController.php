<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class FieldComponentLibraryController extends InstanceComponentLibraryBase {

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
  public function __invoke(RouteMatchInterface $routeMatch) {
    $fieldItem = $this->getFieldDefinitionFromRouteMatch($routeMatch)->getFieldItem();
    return $this->build($fieldItem);
  }

  /**
   * Returns the title.
   */
  public function getTitle(RouteMatchInterface $routeMatch, string $field) {
    $fieldDefinition = $this->getFieldDefinitionFromRouteMatch($routeMatch);
    $entityType = $this->entityTypeManager()->getDefinition($fieldDefinition->getTargetEntityTypeId());
    return $this->t('Select the component to add to %label: %field_label', [
      '%label' => $entityType->getLabel(),
      '%field_label' => $fieldDefinition->getLabel(),
    ]);
  }

}
