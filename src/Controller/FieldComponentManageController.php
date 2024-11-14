<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_icon\IconTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class FieldComponentManageController extends InstanceComponentManageBase {

  use FieldComponentControllerTrait;
  use IconTranslationTrait;

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
    $fieldDefinition = $this->getFieldDefinitionFromRouteMatch($routeMatch);
    $fieldItem = $fieldDefinition->getFieldItem();
    return $this->build($fieldItem);
  }

  /**
   * Builds the title.
   */
  public function getTitle(RouteMatchInterface $routeMatch) {
    $fieldDefinition = $this->getFieldDefinitionFromRouteMatch($routeMatch);
    $entityType = $this->entityTypeManager()->getDefinition($fieldDefinition->getTargetEntityTypeId());
    return $this->t('Default layout for %label: %field_label', [
      '%label' => $entityType->getLabel(),
      '%field_label' => $fieldDefinition->getLabel(),
    ]);
  }

}
