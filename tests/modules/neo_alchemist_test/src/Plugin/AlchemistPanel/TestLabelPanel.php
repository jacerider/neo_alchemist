<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Plugin\AlchemistPanel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\AlchemistPanel;
use Drupal\neo_alchemist\Panel\AlchemistPanelBase;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * A side panel that drafts the host entity's label and sets it on Publish.
 *
 * Inert by default: access is allowed only while the STATE_KEY state flag is
 * on, so enabling the fixture module adds no panel to any other test's editor.
 * The client half is js/test-panel.js — plain JS, to show the registry needs no
 * build step.
 */
#[AlchemistPanel(
  id: 'na_test_label',
  label: new TranslatableMarkup('Test label'),
  icon: 'tag',
)]
final class TestLabelPanel extends AlchemistPanelBase {

  /**
   * The state flag that switches the panel on.
   */
  public const STATE_KEY = 'neo_alchemist_test.panel_enabled';

  /**
   * The state flag that adds the panel's top bar badge.
   */
  public const BADGE_STATE_KEY = 'neo_alchemist_test.panel_badge';

  /**
   * {@inheritdoc}
   */
  public function access(ComponentTreeItem $item, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf((bool) \Drupal::state()->get(self::STATE_KEY, FALSE))
      ->setCacheMaxAge(0)
      ->andIf(parent::access($item, $account));
  }

  /**
   * {@inheritdoc}
   */
  public function build(ComponentTreeItem $item): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['na-test-label-panel']],
      '#attached' => ['library' => ['neo_alchemist_test/panel']],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * A button the client half labels with the drafted label; it opens a
   * dropdown counting the draft changes the badge has heard.
   */
  public function badge(ComponentTreeItem $item): array {
    if (!\Drupal::state()->get(self::BADGE_STATE_KEY, FALSE)) {
      return [];
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Label'),
      '#attributes' => ['type' => 'button', 'class' => ['na-test-label-badge', 'btn', 'btn-xs', 'btn-outline']],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function publish(ComponentTreeItem $item, ContentEntityInterface $entity, mixed $data): void {
    $labelKey = $entity->getEntityType()->getKey('label');
    if ($labelKey && is_array($data) && is_string($data['label'] ?? NULL)) {
      $entity->set($labelKey, $data['label']);
    }
  }

}
