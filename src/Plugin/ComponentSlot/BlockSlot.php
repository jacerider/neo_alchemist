<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\neo_alchemist\Slot\ComponentSlotPluginBase;

/**
 * Plugin implementation of the neo_component_slot.
 */
#[ComponentSlot(
  id: 'block',
  label: new TranslatableMarkup('Block'),
  description: new TranslatableMarkup('Embed a config block.'),
)]
final class BlockSlot extends ComponentSlotPluginBase implements ContainerFactoryPluginInterface {

  use EntityManagerDependentSlotTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'block_id' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    if ($this->configuration['block_id']) {
      $block = $this->entityTypeManager->getStorage('block')->load($this->configuration['block_id']);
      if ($block) {
        $summary[] = $this->t('Block: %block', ['%block' => $block->label() . ' (' . $block->id() . ')']);
      }
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $defaultThemeName = \Drupal::config('system.theme')->get('default');
    $blocks = $this->entityTypeManager->getStorage('block')->loadByProperties([
      'theme' => $defaultThemeName,
      'status' => TRUE,
    ]);
    $options = array_map(fn($block) => $block->label() . ' (' . $block->id() . ')', $blocks);
    $form['block_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Block'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
      '#default_value' => $this->configuration['block_id'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    $block = $this->entityTypeManager->getStorage('block')->load($this->configuration['block_id']);
    if (!$block) {
      return NULL;
    }
    $this->addCacheableDependency($block);
    return $this->entityTypeManager->getViewBuilder('block')->view($block);
  }

}
