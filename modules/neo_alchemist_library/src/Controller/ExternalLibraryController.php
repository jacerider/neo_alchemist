<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_library\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\neo_alchemist_library\Registry\RegistryClient;
use Drupal\neo_alchemist_library\Registry\RegistryException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Browser for the external component registry.
 */
final class ExternalLibraryController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly RegistryClient $registryClient,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_alchemist_library.registry_client'),
    );
  }

  /**
   * Builds the registry browser.
   */
  public function __invoke(): array {
    try {
      $index = $this->registryClient->getIndex();
    }
    catch (RegistryException $e) {
      return [
        '#type' => 'container',
        'message' => [
          '#theme' => 'status_messages',
          '#message_list' => [
            'warning' => [
              $this->t('The component registry could not be loaded: @error', ['@error' => $e->getMessage()]),
              $this->t('Check the <a href=":url">registry settings</a>.', [
                ':url' => Url::fromRoute('neo_alchemist_library.settings')->toString(),
              ]),
            ],
          ],
        ],
      ];
    }

    $rows = [];
    foreach ($index as $name => $component) {
      $row = [];

      // Thumbnail (remote URL).
      $row['thumbnail'] = [];
      if (!empty($component['thumbnail'])) {
        $row['thumbnail']['data'] = [
          '#theme' => 'image',
          '#uri' => $component['thumbnail'],
          '#alt' => $component['title'] ?? $name,
          '#attributes' => [
            'style' => 'display: block; max-width: 100px; max-height: 80px;',
            'loading' => 'lazy',
          ],
        ];
      }

      // Name + description + status + library badges.
      $libraries = $component['libraries'] ?? [];
      $row['name']['data'] = [
        '#type' => 'inline_template',
        '#template' => '
          <strong>{{ title }}</strong> <small>({{ status }})</small>
          {% if description %}<br><small>{{ description }}</small>{% endif %}
          {% if libraries %}<br><small>Libraries: <em>{{ libraries|join(", ") }}</em></small>{% endif %}
          <br><code style="user-select:all;">drush neo:alchemist:add {{ name }}</code>
        ',
        '#context' => [
          'title' => $component['title'] ?? $name,
          'status' => $component['status'] ?? 'stable',
          'description' => $component['description'] ?? '',
          'libraries' => $libraries,
          'name' => $name,
        ],
      ];

      // Operations: install.
      $row['operations']['data'] = [
        '#type' => 'operations',
        '#links' => [
          'install' => [
            'title' => $this->t('Install'),
            'url' => Url::fromRoute('neo_alchemist_library.install', ['component' => $name]),
          ],
        ],
      ];

      $rows[] = $row;
    }

    return [
      '#type' => 'table',
      '#header' => [
        'thumbnail' => $this->t('Thumbnail'),
        'name' => $this->t('Component'),
        'operations' => $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No components are available from the registry yet.'),
      '#attributes' => ['class' => ['neo-alchemist-library']],
      '#cache' => ['max-age' => 3600, 'tags' => [RegistryClient::CACHE_TAG]],
    ];
  }

}
