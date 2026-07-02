<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_block;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\neo_alchemist_block\Entity\AlchemistBlockFieldConfig;

/**
 * Provides a listing of Alchemist blocks.
 */
final class AlchemistBlockListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['id'] = $this->t('Machine name');
    $header['description'] = $this->t('Description');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\neo_alchemist_block\AlchemistBlockInterface $entity */
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['description'] = $entity->getDescription();
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity): array {
    $operations = parent::getDefaultOperations($entity);
    $operations['components'] = [
      'title' => $this->t('Components'),
      'weight' => -10,
      'url' => Url::fromRoute('entity.neo_alchemist_block_host.field_ui.alchemist.manage', [
        'neo_alchemist_block' => $entity->id(),
        'neo_field' => AlchemistBlockFieldConfig::getKeyFromFieldname(AlchemistBlockFieldConfig::FIELD_NAME),
      ]),
    ];
    return $operations;
  }

}
