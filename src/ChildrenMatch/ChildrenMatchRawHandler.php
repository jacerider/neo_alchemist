<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ChildrenMatch;

/**
 * Handles `_raw:*`: a literal the site builder typed.
 *
 * Offers a boolean pair on a boolean shape and a string entry on a string
 * shape. Only the string entry has a form of its own — a select when the shape
 * declares options, a text field otherwise.
 */
final class ChildrenMatchRawHandler extends ChildrenMatchHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'raw';
  }

  /**
   * {@inheritdoc}
   */
  public function addOptions(array &$options, ChildrenMatchFormContext $context): void {
    $shape = $context->shape;
    if ($shape->getType() === 'boolean') {
      $options['- Raw -']['_raw:boolean_true'] = $this->t('Boolean: True');
      $options['- Raw -']['_raw:boolean_false'] = $this->t('Boolean: False');
    }
    if ($shape->getType() === 'string') {
      $options['- Raw -']['_raw:string'] = $shape->getFieldOptions() ? $this->t('Option') : $this->t('String');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array $configuration, ChildrenMatchFormContext $context): ?array {
    // Only the string literal is configurable; the boolean pair needs nothing,
    // so it falls through to the inline value-plugin form like a plain match.
    if (($configuration['field'] ?? NULL) !== '_raw:string') {
      return NULL;
    }
    if ($stringOptions = $context->shape->getFieldOptions()) {
      $form['string'] = [
        '#type' => 'select',
        '#title' => $this->t('Value'),
        '#options' => $stringOptions,
        '#default_value' => $configuration['string'] ?? '',
      ];
      return $form;
    }
    $form['string'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Value'),
      '#default_value' => $configuration['string'] ?? '',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(ChildrenMatchField $field, ChildrenMatchMapper $mapper, ChildrenMatchSourceInterface $source): mixed {
    $fieldName = substr($field->settings['field'], 5);
    return match ($fieldName) {
      'boolean_true' => TRUE,
      'boolean_false' => FALSE,
      'string' => $field->settings['string'] ?? '',
      default => NULL,
    };
  }

}
