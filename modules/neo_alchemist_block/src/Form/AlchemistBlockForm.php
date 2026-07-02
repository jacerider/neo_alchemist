<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_block\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\neo_alchemist_block\Entity\AlchemistBlockFieldConfig;

/**
 * Form for adding and editing Alchemist block entities.
 */
final class AlchemistBlockForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    /** @var \Drupal\neo_alchemist_block\AlchemistBlockInterface $entity */
    $entity = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $entity->label(),
      '#description' => $this->t('The human-readable name of this Alchemist block. It will be shown in the block library.'),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#maxlength' => EntityTypeInterface::BUNDLE_MAX_LENGTH,
      '#machine_name' => [
        'exists' => '\Drupal\neo_alchemist_block\Entity\AlchemistBlock::load',
        'source' => ['label'],
      ],
      '#disabled' => !$entity->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Description'),
      '#maxlength' => 255,
      '#default_value' => $entity->getDescription(),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#description' => $this->t('Disabled blocks are not rendered even when placed in Block layout.'),
      '#default_value' => $entity->status(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = $this->entity->save();
    $message = $result === SAVED_NEW
      ? $this->t('Created the %label Alchemist block.', ['%label' => $this->entity->label()])
      : $this->t('Updated the %label Alchemist block.', ['%label' => $this->entity->label()]);
    $this->messenger()->addStatus($message);
    $form_state->setRedirectUrl(Url::fromRoute('entity.neo_alchemist_block_host.field_ui.alchemist.manage', [
      'neo_alchemist_block' => $this->entity->id(),
      'neo_field' => AlchemistBlockFieldConfig::getKeyFromFieldname(AlchemistBlockFieldConfig::FIELD_NAME),
    ]));
    return $result;
  }

}
