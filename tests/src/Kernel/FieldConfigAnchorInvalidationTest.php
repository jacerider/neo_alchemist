<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use PHPUnit\Framework\Attributes\Group;

/**
 * Customisable-region anchors follow the defaults they are derived from.
 *
 * The anchors are read by getCustomRegions() out of the field's stored
 * defaults and memoised, and isHybrid() is derived from that in turn. The
 * memo had no invalidation, while setComponentValuesFromFieldItem() rewrites
 * exactly those defaults — on the shared, EntityFieldManager-cached definition
 * object, mid-request, on every field-scope save. The object could therefore
 * report a locked layout as hybrid, or the reverse, until caches were rebuilt.
 *
 * @see \Drupal\neo_alchemist\Entity\ComponentFieldConfig::setSetting()
 */
#[Group('neo_alchemist')]
class FieldConfigAnchorInvalidationTest extends HybridFieldKernelTestBase {

  /**
   * Writing the defaults changes what the same object reports as anchors.
   */
  public function testWritingDefaultsDropsTheAnchorMemo(): void {
    $entity = $this->createTestEntity();
    $definition = $this->assertFieldIsHybrid($entity);

    // Warm the memo: the flagged region is an anchor, so this reads as hybrid.
    $this->assertNotSame([], $definition->getCustomRegions(), 'The fixture field exposed no customisable regions.');

    // Any write to 'defaults' invalidates, whoever makes it — the contract
    // every writer inherits, setComponentValuesFromFieldItem() included.
    $definition->setSetting('defaults', []);

    $this->assertSame([], $definition->getCustomRegions(), 'getCustomRegions() answered from defaults recorded before the write.');
    $this->assertFalse($definition->isHybrid(), 'isHybrid() answered from defaults recorded before the write.');
  }

}
