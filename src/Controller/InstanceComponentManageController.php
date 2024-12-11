<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_icon\IconTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentManageController extends ControllerBase {

  use IconTranslationTrait;

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_component_page_renderer')
    );
  }

  /**
   * Builds the response.
   */
  public function tmp(ComponentTreeItem $neo_field) {
    $build = [];

    $build['markup'] = ['#markup' => 'hi'];

    return $build;
  }

  /**
   * Builds the response.
   */
  public function __invoke(ComponentTreeItem $neo_field) {
    $build = [
      '#type' => 'neo_alchemist_manage',
      '#neo_field' => $neo_field,
    ];

    // return $build;

    return $this->bareHtmlPageRenderer->renderBarePage($build, $this->getTitle($neo_field), 'page__neo_alchemist_manage');

    $build = [
      '#attached' => [
        'library' => [
          'neo_alchemist/instance.component.manage',
        ],
        'drupalSettings' => [
          'neoAlchemist' => [
            'baseUrl' => $neo_field->toUrl()->toString(),
          ],
        ],
      ],
    ];

    $build['iframe'] = [
      '#type' => 'html_tag',
      '#tag' => 'iframe',
      '#attributes' => [
        'id' => 'neo-alchemist--iframe',
        'src' => $neo_field->toUrl('preview')->toString(),
        'width' => '100%',
        // 'height' => '800px',
        'frameborder' => '0',
        'class' => [
          'h-displace',
        ],
      ],
    ];

    $build['header'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'class' => [
          'fixed',
          'top-0',
          'left-0',
          'w-full',
          'bg-white',
          'border-b',
          'border-gray-200',
          'p-4',
        ],
        'data-offset-top' => '',
      ],
    ];

    $build['header']['back'] = [
      '#type' => 'link',
      '#title' => $this->adminIcon('Back'),
      '#url' => $neo_field->toUrl('collection'),
    ];

    foreach ([
      'sm' => $this->icon($this->t('Mobile'), 'mobile'),
      'md' => $this->icon($this->t('Tablet'), 'tablet'),
      'lg' => $this->icon($this->t('Desktop'), 'desktop'),
    ] as $key => $label) {
      $build['header'][$key] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => $neo_field->toUrl('collection'),
        '#attributes' => [
          'id' => 'neo-alchemist--resize-' . $key,
          'class' => ['btn', 'btn-xs', 'btn-outline'],
        ],
      ];
    }

    if ($neo_field->access('publish')) {
      $build['publish'] = [
        '#type' => 'link',
        '#title' => $this->adminIcon('Publish'),
        '#url' => $neo_field->toUrl('publish'),
        '#attached' => ['library' => ['core/drupal.dialog.ajax']],
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-primary'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => 700,
          ]),
        ],
      ];
    }
    if ($neo_field->access('revert')) {
      $build['revert'] = [
        '#type' => 'link',
        '#title' => $this->adminIcon('Revert'),
        '#url' => $neo_field->toUrl('revert'),
        '#attached' => ['library' => ['core/drupal.dialog.ajax']],
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-warning'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => 700,
          ]),
        ],
      ];
    }

    if ($neo_field->access('reset')) {
      // Allow reset only for entity-based components.
      $build['reset'] = [
        '#type' => 'link',
        '#title' => $this->adminIcon('Reset'),
        '#url' => $neo_field->toUrl('reset'),
        '#attached' => ['library' => ['core/drupal.dialog.ajax']],
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-alert'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => 700,
          ]),
        ],
      ];
    }

    $build['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
      '#attributes' => [
        'class' => ['p-0 !m-0'],
      ],
      '#prefix' => '<div class="fixed bottom-0 left-0 w-full bg-white border-t border-gray-200 p-4" data-offset-bottom>',
      '#suffix' => '</div>',
    ];
    $build['actions']['add'] = [
      '#type' => 'link',
      '#title' => $this->adminIcon('Add'),
      '#url' => $neo_field->toUrl('library'),
      '#attached' => ['library' => ['core/drupal.dialog.ajax']],
      '#attributes' => [
        'class' => ['use-ajax', 'btn'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => Json::encode([
          'width' => 700,
        ]),
      ],
    ];

    if ($neo_field->access('sort')) {
      $build['actions']['sort'] = [
        '#type' => 'link',
        '#title' => $this->adminIcon('Sort'),
        '#url' => $neo_field->toUrl('sort'),
        '#attached' => ['library' => ['core/drupal.dialog.ajax']],
        '#attributes' => [
          'class' => ['use-ajax', 'btn', 'btn-outline'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => 700,
          ]),
        ],
      ];
    }

    return $this->bareHtmlPageRenderer->renderBarePage($build, $this->getTitle($neo_field), 'page__neo_alchemist_manage');

    $instances = $neo_field->getComponents();
    if ($instances) {
      $build['table'] = [
        '#type' => 'table',
        '#header' => [
          'name' => $this->t('Name'),
          'status' => $this->t('Status'),
          'operations' => $this->t('Operations'),
        ],
        '#rows' => [],
      ];
      foreach ($instances as $instance) {
        $row = [];
        $row['name']['data']['#markup'] = $instance->label() . ' <small>(' . $instance->uuid() . ')</small>';
        if (!$instance->isComponentPublished()) {
          $row['status']['data']['#markup'] = $this->adminIcon('Disabled Globally')->iconOnly()->asTooltip();
        }
        else {
          if ($instance->isPublished()) {
            $row['status']['data']['#markup'] = $this->adminIcon('Enabled')->iconOnly()->asTooltip();
          }
          else {
            $row['status']['data']['#markup'] = $this->adminIcon('Disabled')->iconOnly()->asTooltip();
          }
        }

        $links = [];
        if ($instance->access('update')) {
          $links['edit'] = [
            'title' => $this->adminIcon('Edit'),
            'url' => $instance->toUrl('edit'),
            'attributes' => [
              'class' => ['use-ajax'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => Json::encode([
                'width' => 700,
              ]),
            ],
          ];
        }
        if ($instance->access('sort')) {
          $links['sort'] = [
            'title' => $this->adminIcon('Sort'),
            'url' => $instance->toUrl('sort')->setRouteParameter('uuid', $instance->uuid()),
            'attributes' => [
              'class' => ['use-ajax'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => Json::encode([
                'width' => 700,
              ]),
            ],
          ];
        }
        if ($instance->access('delete')) {
          $links['remove'] = [
            'title' => $this->adminIcon('Remove'),
            'url' => $instance->toUrl('delete'),
            'attributes' => [
              'class' => ['use-ajax'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => Json::encode([
                'width' => 700,
              ]),
            ],
          ];
        }
        $row['operations']['data'] = [
          '#type' => 'operations',
          '#links' => $links,
        ];

        $build['table']['#rows'][] = $row;
      }
    }
    return $build;
  }

  /**
   * Builds the title.
   */
  public function getTitle(ComponentTreeItem $neo_field) {
    $label = $neo_field->belongsToFieldConfig() ? $neo_field->getEntity()->getEntityType()->getLabel() : $neo_field->getEntity()->label();
    return $this->t('@for for %label: %field_label', [
      '@for' => $neo_field->belongsToFieldConfig() ? 'Default layout' : 'Layout',
      '%label' => $label,
      '%field_label' => $neo_field->getFieldDefinition()->getLabel(),
    ]);
  }

}
