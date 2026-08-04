<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\Core\Url;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_icon\IconTrait;
use Drupal\neo_tooltip\TooltipTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists Alchemist-enabled SDCs with a link to preview each one directly.
 *
 * Unlike the Component Library at /admin/config/neo/alchemist/add, these
 * preview links do not require creating a neo_component config entity first.
 */
final class SdcPreviewListController extends ControllerBase {

  use IconTrait;
  use TooltipTrait;

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
    $definitions = array_filter(
      $this->pluginManagerSdc->getDefinitions(),
      fn($definition) => !empty($definition['neo'])
    );
    uasort($definitions, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

    $rows = [];
    foreach ($definitions as $definition) {
      /** @var \Drupal\Core\Plugin\Component $component */
      $component = $this->pluginManagerSdc->createInstance($definition['id']);

      $row = [];
      $row['thumbnail'] = ComponentManageHelper::buildThumbnailCell($component, $definition['name']);

      $info = $this->tooltipAsLink($this->adminIcon('Info', 'info-circle')->iconOnly(), [
        '#markup' => Markup::create('<pre style="white-space:pre-line;">' . $component->metadata->documentation . '</pre>'),
      ]);
      $row['name']['data'] = [
        '#type' => 'inline_template',
        '#template' => '
          <strong>{{ name }}</strong> {{ info }}
          <br><small><code>{{ id }}</code></small>
          {% if description %}<br><small>{{ description }}</small>{% endif %}
        ',
        '#context' => [
          'name' => $definition['name'],
          'id' => $definition['id'],
          'info' => $info,
          'description' => $definition['description'] ?? '',
        ],
      ];

      $row['operations']['data'] = [
        '#type' => 'operations',
        '#links' => [
          'preview' => [
            'title' => $this->t('Preview'),
            'url' => Url::fromRoute('neo_alchemist.sdc_preview', [
              'component' => $definition['id'],
            ]),
          ],
        ],
      ];
      $rows[] = $row;
    }

    return [
      '#type' => 'table',
      '#header' => [
        'thumbnail' => $this->t('Thumbnail'),
        'name' => $this->t('Name'),
        'operations' => $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No Alchemist-enabled SDCs were found.'),
      // Thumbnails are read straight off disk and carry a modified-time token,
      // so a cached render of this table would pin a stale token in place with
      // nothing to invalidate it. There is nothing here worth caching anyway.
      '#cache' => ['max-age' => 0],
    ];
  }

}
