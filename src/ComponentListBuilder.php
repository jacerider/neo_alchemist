<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\neo_icon\IconTrait;

/**
 * Provides a listing of components.
 */
final class ComponentListBuilder extends ConfigEntityListBuilder {
  use IconTrait;

  /**
   * {@inheritdoc}
   */
  protected $limit = 100;

  /**
   * {@inheritdoc}
   */
  protected const SORT_KEY = 'label';

  /**
   * {@inheritdoc}
   */
  public function render() {
    $build = parent::render();

    $groups = [];
    foreach ($build['table']['#rows'] as $row) {
      $groups[$row['entity']['#group']][] = $row;
    }

    $tables = [];
    $service = \Drupal::service('plugin.manager.neo_component_group');
    foreach ($service->getDefinitions() as $id => $definition) {
      if (isset($groups[$id])) {
        $tables[$id] = $build['table'];
        $tables[$id]['#caption'] = [
          'title' => [
            '#markup' => '<div class="text-lg font-bold">' . $definition['label'] . '</div>',
          ],
          'description' => [
            '#markup' => '<div class="text-xs">' . $definition['description'] . '</div>',
          ]
        ];
        $tables[$id]['#rows'] = $groups[$id];
      }
    }
    $build['table'] = $tables;
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['thumbnail'] = $this->t('Thumbnail');
    $header['label'] = $this->t('Label');
    $header['entity'] = $this->t('Scope');
    $header['access'] = $this->t('Access');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\neo_alchemist\ComponentInterface $entity */
    $row['thumbnail'] = [];

    $thumbnail = $entity->getThumbnail();
    if (!$thumbnail) {
      $thumbnail = \Drupal::moduleHandler()->getModule('neo_alchemist')->getPath() . '/images/thumbnail.jpg';
    }
    if ($thumbnail) {
      $row['thumbnail'] = ['style' => 'width: 100px;'];
      $row['thumbnail']['data'] = [
        '#theme' => 'image',
        '#uri' => $thumbnail,
        '#alt' => $entity->label(),
        '#attributes' => [
          'style' => 'display: block; max-width: 80px; max-height: 80px',
        ],
        '#prefix' => '<div class="flex items-center justify-center">',
        '#suffix' => '</div>',
      ];
    }

    $row['label']['data']['title']['#markup'] = $entity->label() . ' <small>(' . $entity->id() . ')</small>';
    if ($description = $entity->getDescription()) {
      $row['label']['data']['description']['#markup'] = '<div><small>' . $description . '</small></div>';
    }

    $targetEntityDefinition = $entity->getTargetEntityTypeDefinition();
    $targetBundle = $entity->getTargetEntityBundle();
    $row['entity'] = [
      '#group' => $entity->getGroup(),
      '#neo_size' => 'min',
      '#neo_style' => 'xs',
      'data' => [
        '#markup' => $targetEntityDefinition ? $targetEntityDefinition->getLabel() . ($targetBundle ? '<br>(' . $targetBundle . ')' : '') : 'All',
      ],
    ];

    $row['access'] = [
      '#neo_size' => 'min',
      '#neo_align' => 'center',
      'data' => [],
    ];
    if ($instances = $entity->getAccessInstances()) {
      $labels = [];
      foreach ($instances as $instance) {
        $labels[] = $instance->label();
      }
      $row['access']['data']['#markup'] = '<div class="text-alert">' . $this->adminIcon($this->t('Limited access by:') . ' ' . implode(', ', $labels), 'lock')->iconOnly()->asTooltip() . '</div>';
    }
    else {
      $row['access']['data']['#markup'] = $this->adminIcon('Allow', 'check-circle')->iconOnly()->asTooltip();
    }
    $row['status'] = $this->statusIcon($entity->status())->iconOnly();
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity) {
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $entity */
    return [
      'customize' => [
        'title' => $this->t('Customize'),
        'weight' => -10,
        'url' => $entity->toUrl(),
      ],
    ] + parent::getDefaultOperations($entity);
  }

}
