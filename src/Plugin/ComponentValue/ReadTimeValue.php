<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;

/**
 * Estimates how long the current page takes to read.
 *
 * The count happens in the browser rather than on the server. A page built with
 * Alchemist has no single text field to measure — the prose is spread across a
 * component tree, and the component asking for the estimate is usually inside
 * that same tree, so rendering it to count words would mean rendering the tree
 * that is currently rendering.
 *
 * So this emits a placeholder carrying its settings as data attributes, and a
 * front-scope library (neo_alchemist/read-time) fills it in and reveals it. The
 * placeholder ships `hidden`, so a page without the library, without JS, or
 * with nothing to count shows nothing at all rather than an empty gap — the
 * consuming component can key its separator off that (see hero_s5).
 */
#[ComponentValue(
  id: 'read_time',
  label: new TranslatableMarkup('Read Time'),
  description: new TranslatableMarkup('Estimate how long the page takes to read. Counted in the browser; the component must attach the neo_alchemist/read-time library.'),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::STRING,
  ],
  weight: 7,
)]
final class ReadTimeValue extends ComponentValuePluginBase {

  /**
   * Minutes shown in the editor preview, where there is no article to count.
   */
  private const PREVIEW_MINUTES = 5;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'words_per_minute' => 200,
      // The theme's readable content region — narrower than <main>, which also
      // carries page furniture.
      'selector' => '.region--content',
      'format' => '@count min read',
      'minimum' => 1,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return FALSE;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['words_per_minute'] = [
      '#type' => 'number',
      '#title' => $this->t('Words per minute'),
      '#description' => $this->t('The average reading speed used to turn a word count into minutes. 200 is a common figure for general prose.'),
      '#default_value' => $this->configuration['words_per_minute'],
      '#min' => 1,
      '#required' => TRUE,
    ];
    $form['selector'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Content selector'),
      '#description' => $this->t('A CSS selector for the element whose words are counted. Defaults to the readable content region; narrow it further if that region carries more than the article itself.'),
      '#default_value' => $this->configuration['selector'],
      '#required' => TRUE,
    ];
    $form['format'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#description' => $this->t('The text shown. <code>@count</code> is replaced with the number of minutes.'),
      '#default_value' => $this->configuration['format'],
      '#required' => TRUE,
    ];
    $form['minimum'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum minutes'),
      '#description' => $this->t('Never report fewer minutes than this, so a short page does not read "0 min read".'),
      '#default_value' => $this->configuration['minimum'],
      '#min' => 0,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * Form validation for the value provider plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $form_state->setValue('words_per_minute', max(1, (int) $form_state->getValue('words_per_minute')));
    $form_state->setValue('minimum', max(0, (int) $form_state->getValue('minimum')));

    if (!str_contains((string) $form_state->getValue('format'), '@count')) {
      $form_state->setError($form['format'], $this->t('The label must contain <code>@count</code>, or the number of minutes has nowhere to go.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    $format = (string) $this->configuration['format'];

    // There is no article to measure in the editor preview, so show a
    // representative figure instead of an element that would stay hidden and
    // make the component look broken while it is being configured.
    if ($this->shape->getComponent()->isPreview()) {
      return str_replace('@count', (string) self::PREVIEW_MINUTES, $format);
    }

    // Rendered as HTML rather than escaped: StringShape wraps any value
    // carrying tags in Markup, so a string prop can hold this placeholder.
    return '<span hidden data-neo-readtime'
      . ' data-neo-readtime-wpm="' . (int) $this->configuration['words_per_minute'] . '"'
      . ' data-neo-readtime-min="' . (int) $this->configuration['minimum'] . '"'
      . ' data-neo-readtime-selector="' . Html::escape((string) $this->configuration['selector']) . '"'
      . ' data-neo-readtime-format="' . Html::escape($format) . '"></span>';
  }

}
