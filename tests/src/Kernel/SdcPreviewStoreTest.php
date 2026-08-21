<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\EditorState\EditorStateStoreInterface;
use Drupal\neo_alchemist\EditorState\MemoryEditorStateStore;
use Drupal\neo_alchemist\EditorState\SdcPreviewStore;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * The SDC preview workspace store, proven on per-user isolation.
 *
 * The SDC preview workspace stays disposable scratch — cache-backed, short
 * expiry, a form that never saves — with one behavioural change this store
 * exists to make: the current user is folded into its key, so one developer's
 * temporary prop overrides on a shared environment are their own. These tests
 * assert what a second developer observes over the in-memory adapter (the seam
 * the store is built on): that they see none of the first developer's
 * overrides, and that their Reset clears their own overrides and not a
 * colleague's.
 *
 * @see \Drupal\neo_alchemist\EditorState\SdcPreviewStore
 * @see \Drupal\neo_alchemist\EditorState\CacheEditorStateStore
 */
#[Group('neo_alchemist')]
class SdcPreviewStoreTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'neo_settings',
    'neo_alchemist',
  ];

  /**
   * The store and its cache adapter are container-registered on the seam.
   */
  public function testStoreAndAdapterAreContainerRegistered(): void {
    $cache = $this->container->get('neo_alchemist.editor_state.cache');
    $store = $this->container->get('neo_alchemist.sdc_preview_store');

    $this->assertInstanceOf(EditorStateStoreInterface::class, $cache, 'The cache adapter implements the seam.');
    $this->assertInstanceOf(SdcPreviewStore::class, $store, 'The SDC preview store is registered.');
  }

  /**
   * Preview overrides stay private per developer, not cleared by a colleague.
   *
   * The overrides live in the SDC preview store: its key folds in the current
   * user, so two developers previewing the same component on one environment
   * never see each other's temporary values or styles, and a Reset clears only
   * the developer who pressed it.
   */
  public function testPreviewOverridesArePrivatePerUser(): void {
    $component = $this->component();
    $currentUser = $this->container->get('current_user');
    $store = new SdcPreviewStore(new MemoryEditorStateStore(), $currentUser);

    $userA = new UserSession(['uid' => 101]);
    $userB = new UserSession(['uid' => 102]);

    // Developer A overrides a value and a style and reads them back.
    $currentUser->setAccount($userA);
    $store->setValues($component, ['props' => ['heading' => ['value' => 'A']]]);
    $store->setStyle($component, 'heading~size', 'lg');
    $this->assertTrue($store->hasValues($component), 'Developer A has value overrides.');
    $this->assertSame('lg', $store->getStyle($component, 'heading~size'), 'Developer A reads back their style override.');

    // Developer B sees none of A's overrides.
    $currentUser->setAccount($userB);
    $this->assertFalse($store->hasValues($component), "Developer B cannot see developer A's value overrides.");
    $this->assertSame([], $store->getValues($component), "Developer B sees an empty override set.");
    $this->assertNull($store->getStyle($component, 'heading~size'), "Developer B cannot see developer A's style override.");

    // Developer B's own overrides are independent, and their Reset clears only
    // their own.
    $store->setValues($component, ['props' => ['heading' => ['value' => 'B']]]);
    $store->resetValues($component);
    $this->assertFalse($store->hasValues($component), 'Developer B cleared their own overrides.');

    // Developer A's overrides survived B writing and resetting the same
    // component.
    $currentUser->setAccount($userA);
    $this->assertSame(
      ['props' => ['heading' => ['value' => 'A']]],
      $store->getValues($component),
      "Developer A's overrides survive developer B's Reset.",
    );
    $this->assertSame('lg', $store->getStyle($component, 'heading~size'), "Developer A's style override survives too.");
  }

  /**
   * The value, style and context slices round-trip through the store.
   */
  public function testEachSliceRoundTrips(): void {
    $component = $this->component();
    $currentUser = $this->container->get('current_user');
    $currentUser->setAccount(new UserSession(['uid' => 101]));
    $store = new SdcPreviewStore(new MemoryEditorStateStore(), $currentUser);

    // Values.
    $this->assertSame([], $store->getValues($component), 'No overrides to start.');
    $store->setValues($component, ['props' => ['heading' => ['value' => 'x']]]);
    $this->assertSame(['props' => ['heading' => ['value' => 'x']]], $store->getValues($component));
    $store->resetValues($component);
    $this->assertSame([], $store->getValues($component), 'resetValues() clears the overrides.');

    // Styles are stored per shape id under one entry.
    $store->setStyle($component, 'a', '1');
    $store->setStyle($component, 'b', '2');
    $this->assertSame(['a' => '1', 'b' => '2'], $store->getStyles($component));
    $this->assertTrue($store->hasStyles($component));
    $store->resetStyles($component);
    $this->assertFalse($store->hasStyles($component), 'resetStyles() clears every style override.');

    // Context normalises an empty selection to NULL.
    $store->setContext($component, 'front:above', '');
    $this->assertSame(['above' => 'front:above', 'below' => NULL], $store->getContext($component));
    $store->resetContext($component);
    $this->assertSame([], $store->getContext($component), 'resetContext() clears the neighbor selection.');
  }

  /**
   * A component with a stable id for keying, not saved or rendered.
   *
   * The store keys only on the component id and never looks the SDC up, so an
   * unsaved entity with an explicit id is all the isolation and round-trip
   * assertions need — no fixture module, no host entity.
   */
  private function component(): Component {
    return Component::create([
      'id' => 'sdc_preview_store_test',
      'label' => 'Preview store fixture',
      'description' => 'Preview store fixture',
      'component' => 'neo_alchemist_test:na_heading',
      'status' => TRUE,
    ]);
  }

}
