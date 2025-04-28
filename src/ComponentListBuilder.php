<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\neo_icon\IconTranslationTrait;

/**
 * Provides a listing of components.
 */
final class ComponentListBuilder extends ConfigEntityListBuilder {
  use IconTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['thumbnail'] = $this->t('Thumbnail');
    $header['label'] = $this->t('Label');
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

    $row['label']['data']['#markup'] = $entity->label() . ' <small>(' . $entity->id() . ')</small>';
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
