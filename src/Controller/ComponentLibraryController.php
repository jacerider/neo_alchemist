<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class ComponentLibraryController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly ComponentPluginManager $pluginManagerSdc,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('plugin.manager.sdc'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(): array {
    $definitions = $this->pluginManagerSdc->getDefinitions();

    $rows = [];
    foreach ($definitions as $definition) {
      $row = [];
      $row['name'] = $definition['name'];

      $links = [];
      $links['add'] = [
        'title' => $this->t('Select'),
        'url' => Url::fromRoute('entity.neo_component.add_form', [
          'component' => $definition['id'],
        ]),
      ];
      if (isset($section)) {
        $links['add']['query']['section'] = $section;
      }
      if (isset($weight)) {
        $links['add']['query']['weight'] = $weight;
      }
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

}
