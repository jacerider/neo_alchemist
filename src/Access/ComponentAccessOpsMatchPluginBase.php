<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_icon\IconTrait;

/**
 * Base class for access plugins that gate each operation on a selection.
 *
 * Several access plugins share one settings shape: per operation, a set of
 * selected things plus whether the account must match any or all of them.
 * Only three things actually vary between them — what is being selected, how
 * it is picked in the form, and how an account is tested against it — so the
 * per-op schema, its form, its validation and the access skeleton live here.
 *
 * Stored configuration is `['ops' => [$op => [<value key> => [...], 'match' =>
 * 'any'|'all']]]`, with an operation absent entirely when nothing is selected.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentAccess\RoleAccess
 * @see \Drupal\neo_alchemist\Plugin\ComponentAccess\PermissionAccess
 */
abstract class ComponentAccessOpsMatchPluginBase extends ComponentAccessPluginBase {

  use IconTrait;

  /**
   * The configuration key holding the selection for an operation.
   *
   * @return string
   *   The value key, e.g. 'roles' or 'permissions'.
   */
  abstract protected function getValueKey(): string;

  /**
   * The human-readable name of what is being selected.
   *
   * Used for the selection element's title, the summary line and the match
   * radio labels, so those cannot describe different things.
   *
   * @return string
   *   A plural noun, e.g. 'Roles' or 'Permissions'.
   */
  abstract protected function getValueLabel(): string;

  /**
   * Builds the form element that picks the selection for one operation.
   *
   * @param array $default
   *   The currently selected values.
   *
   * @return array
   *   A form element. Its value must be the selection.
   */
  abstract protected function buildSelectionElement(array $default): array;

  /**
   * Renders the selection for the settings summary.
   *
   * @param array $selected
   *   The selected values.
   *
   * @return string
   *   A human-readable list.
   */
  abstract protected function summarizeSelection(array $selected): string;

  /**
   * Decides whether an account satisfies a selection.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to test.
   * @param array $selected
   *   The selected values.
   * @param string $match
   *   Either 'any' or 'all'.
   *
   * @return bool
   *   TRUE when the account matches.
   */
  abstract protected function accountMatches(AccountInterface $account, array $selected, string $match): bool;

  /**
   * The message shown when access is denied.
   *
   * @return string
   *   The reason.
   */
  abstract protected function getForbiddenReason(): string;

  /**
   * Cache contexts that make a denial safe to cache.
   *
   * Whatever the decision is read from has to vary the cache, or two accounts
   * that differ only in that respect share an answer.
   *
   * @return string[]
   *   Cache contexts.
   */
  abstract protected function getAccessCacheContexts(): array;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'ops' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    foreach (ComponentAccessInterface::OPS as $op => $info) {
      $config = $this->configuration['ops'][$op] ?? [];
      if (!$config) {
        continue;
      }
      $summary[] = strtoupper($info['label']);
      $summary[] = '- ' . $this->t('@label: @values', [
        '@label' => $this->getValueLabel(),
        '@values' => $this->summarizeSelection($config[$this->getValueKey()] ?? []),
      ]);
      $summary[] = '- ' . $this->t('Match: @match', [
        '@match' => ($config['match'] ?? 'any') === 'any' ? $this->t('Any') : $this->t('All'),
      ]);
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $valueKey = $this->getValueKey();
    $form['ops'] = [
      '#type' => 'container',
    ];
    foreach (ComponentAccessInterface::OPS as $op => $info) {
      $row = [
        '#type' => 'details',
        '#title' => $this->adminIcon($info['label']),
        '#description' => $info['description'],
        '#description_display' => 'before',
        '#open' => !empty($this->configuration['ops'][$op]),
      ];
      $row[$valueKey] = $this->buildSelectionElement($this->configuration['ops'][$op][$valueKey] ?? []);
      $row['match'] = [
        '#type' => 'radios',
        '#title' => $this->t('Match'),
        '#options' => [
          'any' => $this->t('Has any of the selected @label', ['@label' => strtolower($this->getValueLabel())]),
          'all' => $this->t('Has all of the selected @label', ['@label' => strtolower($this->getValueLabel())]),
        ],
        '#default_value' => $this->configuration['ops'][$op]['match'] ?? 'any',
        '#required' => TRUE,
      ];
      $form['ops'][$op] = $row;
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $valueKey = $this->getValueKey();
    foreach (array_keys(ComponentAccessInterface::OPS) as $op) {
      $selected = array_filter($form_state->getValue(['ops', $op, $valueKey], []));
      if ($selected) {
        $form_state->setValue(['ops', $op, $valueKey], $selected);
      }
      else {
        // An operation with nothing selected gates nothing; drop it so
        // access() can treat "absent" as "not configured".
        $form_state->unsetValue(['ops', $op]);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access(string $op, AccountInterface $account): AccessResultInterface {
    $config = $this->configuration['ops'][$op] ?? [];
    if (!$config) {
      return AccessResult::neutral();
    }
    $selected = $config[$this->getValueKey()] ?? [];
    // Both outcomes depend on the account, so both carry the contexts: a
    // neutral result that did not vary would let a render cache hand one
    // account's pass to another account that should be refused.
    $matched = $this->accountMatches($account, $selected, $config['match'] ?? 'any');
    $result = $matched
      ? AccessResult::neutral()
      : AccessResult::forbidden($this->getForbiddenReason());
    return $result->addCacheContexts($this->getAccessCacheContexts());
  }

}
