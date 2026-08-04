<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentUsage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists everywhere a component is used.
 */
final class ComponentUsageController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    protected ComponentUsage $componentUsage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_alchemist.component_usage'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(ComponentInterface $neo_component): array {
    $usages = $this->componentUsage->getUsages($neo_component->id());
    $sections = [
      'content' => [
        'title' => $this->t('Content'),
        'description' => $this->t('Entities where an editor placed this component.'),
        'empty' => $this->t('This component has not been placed on any content.'),
      ],
      'default' => [
        'title' => $this->t('Default layouts'),
        'description' => $this->t('Fields whose default layout includes this component. Every entity of that bundle renders it unless the layout is overridden.'),
        'empty' => $this->t('This component is not part of any default layout.'),
      ],
      'block' => [
        'title' => $this->t('Alchemist blocks'),
        'description' => $this->t('Blocks built from this component.'),
        'empty' => $this->t('This component is not used in any Alchemist block.'),
      ],
    ];

    $build = [];
    $total = 0;
    foreach ($sections as $type => $section) {
      // The blocks section is only meaningful when the submodule is enabled.
      if ($type === 'block' && !$this->moduleHandler()->moduleExists('neo_alchemist_block')) {
        continue;
      }
      $rows = [];
      foreach ($usages[$type] as $usage) {
        $label = $usage['url']
          ? Link::fromTextAndUrl($usage['label'], $usage['url'])->toRenderable()
          : ['#markup' => $usage['label']];
        $rows[] = [
          'label' => ['data' => $label],
          'context' => ['data' => ['#markup' => $usage['context']]],
        ];
      }
      $total += count($rows);
      $build[$type] = [
        '#type' => 'table',
        '#header' => [
          'label' => $section['title'],
          'context' => $this->t('Location'),
        ],
        '#rows' => $rows,
        '#empty' => $section['empty'],
        '#caption' => [
          'title' => ['#markup' => '<div class="text-lg font-bold">' . $section['title'] . '</div>'],
          'description' => ['#markup' => '<div class="text-xs">' . $section['description'] . '</div>'],
        ],
        '#attributes' => ['class' => ['mb-6']],
      ];
    }

    if (!$total) {
      $build['#prefix'] = '<div class="card p-3 mb-6">' . $this->t('%label is not used anywhere. It can be safely changed or deleted.', [
        '%label' => $neo_component->label(),
      ]) . '</div>';
    }

    $build['#cache']['tags'] = $this->componentUsage->getCacheTags();
    return $build;
  }

  /**
   * Returns the title.
   */
  public function getTitle(ComponentInterface $neo_component) {
    return $this->t('Usage of %label', ['%label' => $neo_component->label()]);
  }

}
