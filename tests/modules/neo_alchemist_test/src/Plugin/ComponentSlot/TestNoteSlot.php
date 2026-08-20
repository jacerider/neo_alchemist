<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Plugin\ComponentSlot;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\neo_alchemist\Slot\ComponentSlotPluginBase;

/**
 * A slot plugin with one settings field, for driving the slot form.
 *
 * Every shipped slot plugin needs views, block, commerce or a host entity
 * type before it will build a settings form, which makes none of them usable
 * for testing the form's own staging behaviour. This one carries a single
 * textfield so a test can commit a value, cancel over it, and see which won.
 */
#[ComponentSlot(
  id: 'na_note',
  label: new TranslatableMarkup('Test note'),
  description: new TranslatableMarkup('Holds one line of text.'),
)]
final class TestNoteSlot extends ComponentSlotPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['note' => ''] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $note = $this->getConfiguration()['note'] ?? '';
    return $note === '' ? [] : [$note];
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['note'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Note'),
      '#default_value' => $this->getConfiguration()['note'] ?? '',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    $note = $this->getConfiguration()['note'] ?? '';
    return $note === '' ? [] : ['#markup' => $note];
  }

}
