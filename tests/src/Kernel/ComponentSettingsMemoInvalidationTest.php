<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\Tests\neo_alchemist\Traits\SdcPreviewStoreTestTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Derived state stays consistent with settings within one request.
 *
 * Component memoises four things derived from $settings — prop shapes, slots,
 * filters and access instances. Every mutator that writes $settings therefore
 * owes the matching memo an invalidation, and the mutators used to carry that
 * obligation individually: some remembered, some did not, so within a single
 * request the object could report a filter that had just been deleted or slot
 * settings from before an edit.
 *
 * These are read-after-write assertions, one per mutator, so a future mutator
 * that writes settings without dropping derived state fails here rather than
 * in a form-submit path.
 *
 * @see \Drupal\neo_alchemist\Entity\Component::setSetting()
 */
#[Group('neo_alchemist')]
class ComponentSettingsMemoInvalidationTest extends KernelTestBase {

  use SdcPreviewStoreTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * Creates a fixture component.
   */
  private function createComponent(string $id): Component {
    $component = Component::create([
      'id' => $id,
      'label' => $id,
      'description' => $id,
      'component' => 'neo_alchemist_test:' . $id,
      'status' => TRUE,
    ]);
    $component->save();
    return $component;
  }

  /**
   * Adds a filter and returns its uuid.
   */
  private function addFilter(Component $component): string {
    $filter = \Drupal::service('neo_component.filter.factory')->get($component, [
      'title' => 'Fixture filter',
      'plugin_id' => 'string',
      'plugin_settings' => [],
      // Only an editable filter picks up preview overrides in getFilters().
      'editable' => TRUE,
    ]);
    return $component->setFilter($filter)->uuid();
  }

  /**
   * Adds an access rule and returns its uuid.
   */
  private function addAccess(Component $component): string {
    $access = \Drupal::service('neo_component.access.factory')->get($component, [
      'plugin_id' => 'protected',
      'plugin_settings' => [],
    ]);
    return $component->setAccess($access)->uuid();
  }

  /**
   * A deleted filter is gone from the instance that deleted it.
   */
  public function testDeleteFilterDropsTheFilterMemo(): void {
    $component = $this->createComponent('na_leaf');
    $uuid = $this->addFilter($component);

    // Warm the memo, as any read before the delete would.
    $this->assertNotNull($component->getFilter($uuid), 'The fixture filter was not stored.');

    $component->deleteFilter($uuid);

    $this->assertNull($component->getFilter($uuid), 'getFilter() returned a filter that was just deleted.');
    $this->assertArrayNotHasKey($uuid, $component->getFilters(), 'getFilters() still lists a deleted filter.');
  }

  /**
   * A deleted access rule is gone from the instance that deleted it.
   */
  public function testDeleteAccessDropsTheAccessMemo(): void {
    $component = $this->createComponent('na_leaf');
    $uuid = $this->addAccess($component);

    $this->assertNotNull($component->getAccess($uuid), 'The fixture access rule was not stored.');

    $component->deleteAccess($uuid);

    $this->assertNull($component->getAccess($uuid), 'getAccess() returned an access rule that was just deleted.');
    $this->assertArrayNotHasKey($uuid, $component->getAccessInstances(), 'getAccessInstances() still lists a deleted rule.');
  }

  /**
   * Slot settings written mid-request are visible to the next read.
   */
  public function testSetSlotSettingsDropsTheSlotMemo(): void {
    $component = $this->createComponent('na_slot_host');

    $slots = $component->getSlots();
    $this->assertArrayHasKey('body', $slots, 'The slot fixture did not expose its slot.');

    // getSlots() bakes the stored settings into each ComponentSlot at
    // construction, so a surviving memo keeps serving slot objects built from
    // the pre-write settings. Read the key back off the slot object rather
    // than through getSlotSettings(), which reads $settings directly and so
    // cannot observe the memo either way.
    $component->setSlotSettings($slots['body'], [
      'plugins' => [
        'fixture-uuid' => ['plugin' => 'block', 'key' => 'AFTER_EDIT'],
      ],
    ]);

    $this->assertSame(
      'AFTER_EDIT',
      $component->getSlots()['body']->getKey('fixture-uuid'),
      'getSlots() served a slot object built from settings recorded before the write.',
    );
  }

  /**
   * Preview overrides are reflected by the filters built after they are set.
   */
  public function testSetPreviewValuesDropsTheFilterMemo(): void {
    $component = $this->createComponent('na_leaf');
    $uuid = $this->addFilter($component);

    // getValues() only surfaces the overrides for a config-scope component in
    // preview, which is the SDC preview workspace this path exists for.
    $component->setPreview(TRUE);
    $this->assertSame('config', $component->getScope(), 'The fixture left config scope.');

    // getFilters() bakes preview override values into each filter instance, so
    // a memo held across setPreviewValues() serves pre-override filters.
    $component->getFilters();
    $this->setPreviewValues($component, ['filters' => [$uuid => ['value' => 'OVERRIDDEN']]]);

    $filter = $component->getFilter($uuid);
    $this->assertNotNull($filter, 'The filter disappeared after setting preview values.');
    $this->assertSame('OVERRIDDEN', $filter->getValue(), 'The filter memo survived setPreviewValues() and served a stale override.');
  }

}
