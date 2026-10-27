<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Panel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_alchemist\Attribute\AlchemistPanel;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;

/**
 * Discovers and gates the Alchemist editor's side panels.
 *
 * Owns the two rules every caller must agree on: panels exist only in the
 * entity scope, and a panel the account may not access is never listed, read,
 * written or published.
 *
 * @see \Drupal\neo_alchemist\Panel\AlchemistPanelInterface
 */
class AlchemistPanelManager extends DefaultPluginManager {

  /**
   * Constructs the panel plugin manager.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/AlchemistPanel', $namespaces, $module_handler, AlchemistPanelInterface::class, AlchemistPanel::class);
    $this->alterInfo('neo_alchemist_panel_info');
    $this->setCacheBackend($cache_backend, 'neo_alchemist_panel_plugins');
  }

  /**
   * Whether the account may use a panel on a field item.
   *
   * Forbidden outright for an unknown panel id and outside the entity scope;
   * otherwise the panel's own access().
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item being edited.
   * @param string $panelId
   *   The panel plugin id.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(ComponentTreeItem $item, string $panelId, AccountInterface $account): AccessResultInterface {
    if ($item->belongsToFieldConfig()) {
      return AccessResult::forbidden('Editor panels exist only in the entity scope.');
    }
    if (!$this->hasDefinition($panelId)) {
      return AccessResult::forbidden('Unknown editor panel.')->addCacheTags(['neo_alchemist_panel_plugins']);
    }
    return $this->panel($panelId)->access($item, $account);
  }

  /**
   * The panels the account may use on a field item, in toggle order.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item being edited.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface|null $cacheability
   *   (optional) Collects the cacheability of every access decision, for the
   *   caller to apply to what it renders.
   *
   * @return \Drupal\neo_alchemist\Panel\AlchemistPanelInterface[]
   *   The accessible panels keyed by id, sorted by weight then label.
   */
  public function accessiblePanels(ComponentTreeItem $item, AccountInterface $account, ?RefinableCacheableDependencyInterface $cacheability = NULL): array {
    $cacheability?->addCacheTags(['neo_alchemist_panel_plugins']);
    if ($item->belongsToFieldConfig()) {
      return [];
    }
    $definitions = $this->getDefinitions();
    uasort($definitions, static fn (array $a, array $b): int => [
      $a['weight'] ?? 0,
      (string) $a['label'],
    ] <=> [
      $b['weight'] ?? 0,
      (string) $b['label'],
    ]);
    $panels = [];
    foreach (array_keys($definitions) as $id) {
      $panel = $this->panel($id);
      $access = $panel->access($item, $account);
      $cacheability?->addCacheableDependency($access);
      if ($access->isAllowed()) {
        $panels[$id] = $panel;
      }
    }
    return $panels;
  }

  /**
   * Hands each panel's drafted data to its publish(), before the entity saves.
   *
   * Data for a panel that no longer exists (its module was uninstalled) or
   * that the publishing account may not access is dropped, not applied.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item being published.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity about to be saved.
   * @param array $panelData
   *   Panel id => drafted data, as read from the draft before it was deleted.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The publishing account.
   */
  public function publish(ComponentTreeItem $item, ContentEntityInterface $entity, array $panelData, AccountInterface $account): void {
    foreach ($panelData as $panelId => $data) {
      if ($data === NULL || !$this->access($item, (string) $panelId, $account)->isAllowed()) {
        continue;
      }
      $this->panel((string) $panelId)->publish($item, $entity, $data);
    }
  }

  /**
   * Whether any panel's drafted data is an edit.
   *
   * A panel this site no longer has counts as one: nothing can say its data
   * is safe to drop.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $item
   *   The field item being edited.
   * @param array $panelData
   *   Panel id => drafted data, as the shared draft store holds it.
   *
   * @return bool
   *   TRUE when some panel holds an edit.
   */
  public function edited(ComponentTreeItem $item, array $panelData): bool {
    foreach ($panelData as $panelId => $data) {
      if (!$this->hasDefinition((string) $panelId) || $this->panel((string) $panelId)->isEdited($item, $data)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Instantiates a panel.
   */
  public function panel(string $panelId): AlchemistPanelInterface {
    $panel = $this->createInstance($panelId);
    assert($panel instanceof AlchemistPanelInterface);
    return $panel;
  }

}
