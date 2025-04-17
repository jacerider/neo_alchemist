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
final class FieldComponentController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly EntityFieldManagerInterface $entityFieldManager,
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
    $build = [];

    $fieldDefinitions = $this->getFieldDefinitionsFromRouteMatch($routeMatch);
    if (count($fieldDefinitions) === 1) {
      $url = reset($fieldDefinitions)->toUrl();
      $destination = $request->query->get('destination');
      $request->query->set('destination', NULL);
      return $this->redirect($url->getRouteName(), $url->getRouteParameters(), [
        'query' => ['destination' => $destination],
      ]);
    }

    $rows = [];
    foreach ($fieldDefinitions as $definition) {
      $row = [];
      $row['name'] = $definition->getLabel();

      $links = [];
      $links['add'] = [
        'title' => $this->t('Select'),
        'url' => $definition->toUrl(),
      ];
      $row['operations']['data'] = [
        '#type' => 'operations',
        '#links' => $links,
      ];
      $rows[] = $row;
    }
    $build = [
      '#type' => 'table',
      '#header' => [
        'name' => $this->t('Name'),
        'operations' => $this->t('Operations'),
      ],
      '#rows' => $rows,
    ];

    return $build;
  }

  /**
   * Returns the title.
   */
  public function getTitle(RouteMatchInterface $routeMatch) {
    $fieldDefinitions = $this->getFieldDefinitionsFromRouteMatch($routeMatch);
    if (count($fieldDefinitions) === 1) {
      return $this->t('Manage Layout');
    }
    return $this->t('Manage Layouts');
  }

  /**
   * Retrieves field definitions from route match.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The route match.
   *
   * @return \Drupal\neo_alchemist\ComponentFieldConfigInterface[]
   *   The field definitions as determined from the passed-in route match.
   */
  protected function getFieldDefinitionsFromRouteMatch(RouteMatchInterface $routeMatch) {
    $parameters = $routeMatch->getParameters();
    $entityTypeId = $parameters->get('entity_type_id');
    $bundle = $parameters->get('bundle') ?? $entityTypeId;
    return array_filter($this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle), fn($field) => $field->getType() === 'neo_component_tree');
  }

}
