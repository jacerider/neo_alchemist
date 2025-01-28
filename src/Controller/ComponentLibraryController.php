<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\Url;
use Drupal\neo_icon\IconTranslationTrait;
use Drupal\neo_tooltip\TooltipTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class ComponentLibraryController extends ControllerBase {

  use IconTranslationTrait;
  use TooltipTrait;

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
      /** @var \Drupal\Core\Plugin\Component $component */
      $component = $this->pluginManagerSdc->createInstance($definition['id']);

      $row = [];
      $row['thumbnail'] = [];
      if ($thumbnail = $component->metadata->getThumbnailPath()) {
        $row['thumbnail'] = ['style' => 'width: 100px;'];
        $row['thumbnail']['data'] = [
          '#theme' => 'image',
          '#uri' => $thumbnail,
          '#alt' => $definition['name'],
          '#attributes' => [
            'style' => 'display: block; max-width: 80px; max-height: 80px',
          ],
          '#prefix' => '<div class="flex items-center justify-center">',
          '#suffix' => '</div>',
        ];
      }

      $info = $this->tooltipAsLink($this->adminIcon('Info', 'info-circle')->iconOnly(), [
        '#markup' => Markup::create('<pre style="white-space:pre-line;">' . $component->metadata->documentation . '</pre>'),
      ]);
      $row['name']['data'] = [
        '#type' => 'inline_template',
        '#template' => '
          {{ name }} {{ info }}
          {% if description %}<br><small>{{ description }}</small>{% endif %}
          {% if path %}<br><small>Path: <em>{{ path }}</em></small>{% endif %}
        ',
        '#context' => [
          'name' => $definition['name'],
          'info' => $info,
          'description' => $definition['description'],
          'path' => $component->metadata->path,
        ],
      ];

      $links = [];
      $links['add'] = [
        'title' => $this->t('Select'),
        'url' => Url::fromRoute('entity.neo_component.add_form', [
          'component' => $definition['id'],
        ]),
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
        'thumbnail' => $this->t('Thumbnail'),
        'name' => $this->t('Name'),
        'operations' => $this->t('Operations'),
      ],
      '#rows' => $rows,
    ];

    return $build;
  }

}
