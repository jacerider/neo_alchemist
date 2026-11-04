<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * The hydrated tree: depth-first consumption, publishing, and access.
 *
 * ComponentTreeStructureTest pins the depth-first GENERATOR; this pins its
 * consumer, ComponentTreeHydrated::getValue()/renderify():
 * - an unpublished parent drops its whole subtree with no orphans floating
 *   to the root;
 * - a stored tree referencing a slot the component no longer declares must
 *   not warn (failOnWarning turns that into a failure — the pre-fix code
 *   indexed the slot key unguarded);
 * - renderify skips access-forbidden components AND captures the access
 *   result's cacheability, so a hidden component is re-evaluated when its
 *   dependencies change.
 *
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeHydrated
 */
#[Group('neo_alchemist')]
class ComponentTreeHydratedTest extends HybridFieldKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * These tests want plain custom mode: arbitrary per-entity trees.
   */
  protected function setUp(): void {
    parent::setUp();
    $field = FieldConfig::loadByName('entity_test', 'entity_test', static::FIELD_NAME);
    $field->setSetting('allow_custom', TRUE);
    $field->setSetting('defaults', []);
    $field->save();
    $this->resetFieldCaches('na_region_host');
  }

  /**
   * Builds an entity item from a tree/props pair.
   */
  private function buildItem(array $tree, array $props): ComponentTreeItem {
    $entity = $this->createTestEntity();
    $entity->set(static::FIELD_NAME, [['tree' => $tree, 'props' => $props]]);
    $entity->save();
    $item = $this->reloadEntity($entity)->get(static::FIELD_NAME)->first();
    $this->assertInstanceOf(ComponentTreeItem::class, $item);
    return $item;
  }

  /**
   * An unpublished parent drops its whole subtree, leaving no orphans.
   */
  public function testUnpublishedParentDropsSubtree(): void {
    $item = $this->buildItem(
      [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'p-host', 'component' => 'na_region_host'],
          ['uuid' => 'sibling', 'component' => 'na_leaf'],
        ],
        'p-host' => ['body' => [['uuid' => 'child', 'component' => 'na_leaf']]],
      ],
      [
        // The host is unpublished per-instance.
        'p-host' => ['status' => FALSE, 'props' => []],
        'child' => $this->leafProps('CHILD'),
        'sibling' => $this->leafProps('SIBLING'),
      ],
    );

    $value = $item->get('hydrated')->getValue();
    $root = $value[ComponentTreeStructure::ROOT_UUID];

    $this->assertArrayNotHasKey('p-host', $root, 'The unpublished parent is gone.');
    $this->assertArrayHasKey('sibling', $root, 'The published sibling survived.');
    $this->assertArrayNotHasKey('child', $root, 'The unpublished parent\'s child did not float to the root.');
    $flattened = json_encode($value);
    $this->assertStringNotContainsString('child', $flattened, 'The dropped child appears nowhere in the hydrated tree.');
  }

  /**
   * A stored slot the component no longer declares fails loudly in dev.
   *
   * With zend.assertions=1 the in-code guard in the component instance
   * ("The specified shape must exist on the parent component") fires before
   * the hydration ever reaches the slot read — a malformed tree is a
   * development-time scream, not a silent mis-render. In production
   * (assertions compiled out) the hydration proceeds, which is why the slot
   * read itself is null-guarded: an undefined-array-key warning there would
   * be log noise on every render of the stale tree.
   */
  public function testStoredSlotNoLongerDeclaredFailsLoudlyInDev(): void {
    $this->assertSame('1', ini_get('zend.assertions'), 'Assertions must be live for the in-code guard to be meaningful.');
    $item = $this->buildItem(
      [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'p-host', 'component' => 'na_region_host'],
        ],
        // `gone` is not a region prop on na_region_host.
        'p-host' => ['gone' => [['uuid' => 'child', 'component' => 'na_leaf']]],
      ],
      [
        'p-host' => ['status' => TRUE, 'props' => []],
        'child' => $this->leafProps('CHILD'),
      ],
    );

    $this->expectException(\AssertionError::class);
    $this->expectExceptionMessage('The specified shape must exist on the parent component');
    $item->get('hydrated')->getValue();
  }

  /**
   * The render pass skips forbidden components, keeping their cacheability.
   */
  public function testAccessForbiddenComponentSkippedWithCacheability(): void {
    // renderify() renders only isAllowed() results, so the baseline entity
    // view grant must exist for the anonymous test user.
    Role::create(['id' => RoleInterface::ANONYMOUS_ID, 'label' => 'Anonymous'])
      ->grantPermission('view test entity')
      ->save();
    // Forbid na_leaf via an access plugin carrying a sentinel tag.
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_leaf')
      ->set('settings.access', [
        'access-forbid' => [
          'plugin_id' => 'na_cache_tag_access',
          'plugin_settings' => ['forbid' => TRUE, 'tag' => 'na_access_sentinel'],
        ],
      ])
      ->save();
    $this->container->get('entity_type.manager')->getStorage('neo_component')->resetCache(['na_leaf']);

    $item = $this->buildItem(
      [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'hidden-leaf', 'component' => 'na_leaf'],
          ['uuid' => 'shown-host', 'component' => 'na_region_host'],
        ],
      ],
      [
        'hidden-leaf' => $this->leafProps('HIDDEN'),
        'shown-host' => ['status' => TRUE, 'props' => []],
      ],
    );

    $build = $item->toRenderable();
    $root = $build[ComponentTreeStructure::ROOT_UUID] ?? [];

    $this->assertArrayNotHasKey('hidden-leaf', $root, 'The forbidden component was not rendered.');
    $this->assertArrayHasKey('shown-host', $root, 'The allowed sibling rendered.');
    $this->assertContains('na_access_sentinel', $build['#cache']['tags'] ?? [], 'The access decision\'s cacheability was captured, so the hidden component is re-evaluated when its dependencies change.');
  }

  /**
   * It renders a single instance identically to its markup in the full tree.
   */
  public function testRenderComponentMatchesFullRender(): void {
    $this->grantAnonymousView();
    $item = $this->nestedItem();

    $full = $this->renderMarkup($item->toRenderable());
    $single = $this->renderMarkup($item->renderComponent('sibling'));

    $this->assertStringContainsString('SIBLING', $single);
    $this->assertStringNotContainsString('CHILD', $single, 'Only the asked-for instance renders.');
    $this->assertStringContainsString(trim($single), $full, 'The instance renders as it does inside the full tree.');
  }

  /**
   * It renders an instance nested in another instance's region.
   */
  public function testRenderComponentNested(): void {
    $this->grantAnonymousView();
    $item = $this->nestedItem();

    $host = $this->renderMarkup($item->renderComponent('host'));
    $child = $this->renderMarkup($item->renderComponent('child'));

    $this->assertStringContainsString('CHILD', $host, 'A host renders its region\'s children.');
    $this->assertStringContainsString('CHILD', $child);
    $this->assertStringContainsString(trim($child), $this->renderMarkup($item->toRenderable()));
  }

  /**
   * It returns an empty render array for an unknown uuid.
   */
  public function testRenderComponentUnknown(): void {
    $this->grantAnonymousView();
    $build = $this->nestedItem()->renderComponent('nope');

    $this->assertSame('', trim($this->renderMarkup($build)));
    $this->assertArrayNotHasKey('#type', $build);
  }

  /**
   * It respects the instance's and its ancestors' access rules.
   */
  public function testRenderComponentAccess(): void {
    $this->grantAnonymousView();
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.na_region_host')
      ->set('settings.access', [
        'access-forbid' => [
          'plugin_id' => 'na_cache_tag_access',
          'plugin_settings' => ['forbid' => TRUE, 'tag' => 'na_access_sentinel'],
        ],
      ])
      ->save();
    $this->container->get('entity_type.manager')->getStorage('neo_component')->resetCache(['na_region_host']);
    $item = $this->nestedItem();

    $this->assertSame('', trim($this->renderMarkup($item->renderComponent('host'))), 'A forbidden instance renders nothing.');
    $child = $item->renderComponent('child');
    $this->assertSame('', trim($this->renderMarkup($child)), 'A child of a forbidden instance renders nothing.');
    $this->assertContains('na_access_sentinel', $child['#cache']['tags'] ?? []);
    $this->assertStringContainsString('SIBLING', $this->renderMarkup($item->renderComponent('sibling')));
  }

  /**
   * A host with a child in its region, and a sibling leaf.
   */
  private function nestedItem(): ComponentTreeItem {
    return $this->buildItem(
      [
        ComponentTreeStructure::ROOT_UUID => [
          ['uuid' => 'host', 'component' => 'na_region_host'],
          ['uuid' => 'sibling', 'component' => 'na_leaf'],
        ],
        'host' => ['body' => [['uuid' => 'child', 'component' => 'na_leaf']]],
      ],
      [
        'host' => ['status' => TRUE, 'props' => []],
        'child' => $this->leafProps('CHILD'),
        'sibling' => $this->leafProps('SIBLING'),
      ],
    );
  }

  /**
   * Lets the anonymous test user view test entities.
   */
  private function grantAnonymousView(): void {
    Role::create(['id' => RoleInterface::ANONYMOUS_ID, 'label' => 'Anonymous'])
      ->grantPermission('view test entity')
      ->save();
  }

  /**
   * Renders a build to markup.
   */
  private function renderMarkup(array $build): string {
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

}
