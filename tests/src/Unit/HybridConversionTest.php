<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A stored full layout converts to the subset hybrid mode reads.
 *
 * A field in custom mode stores each entity's whole tree, under uuids of the
 * entity's own. Hybrid mode finds an entity's region content by the uuid of
 * the component that owns the region *in the field default layout*, so
 * switching a field over leaves every stored layout unreadable: nothing is
 * lost, nothing renders. The conversion matches the stored structure to the
 * default by component and position, and re-keys the region content.
 *
 * It refuses rather than guesses. A stored layout whose locked structure
 * differs from the default holds content hybrid mode has no place for.
 *
 * @see \Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure::convertToHybridStorage()
 */
#[Group('neo_alchemist')]
class HybridConversionTest extends UnitTestCase {

  private const ROOT = ComponentTreeStructure::ROOT_UUID;

  /**
   * The default layout: root → host, with an empty flagged body region.
   */
  private function defaults(): array {
    return [
      'tree' => [
        self::ROOT => [['uuid' => 'd-host', 'component' => 'host']],
      ],
      'props' => [
        'd-host' => ['status' => TRUE, 'props' => []],
      ],
    ];
  }

  /**
   * The body region of the default's host is entity-customizable.
   */
  private function anchors(): array {
    return ['d-host' => ['slots' => ['body']]];
  }

  /**
   * An entity's own full copy: its own host uuid, two leaves in the body.
   */
  private function stored(): array {
    return [
      'tree' => [
        self::ROOT => [['uuid' => 's-host', 'component' => 'host']],
        's-host' => [
          'body' => [
            ['uuid' => 'leaf-1', 'component' => 'leaf'],
            ['uuid' => 'box', 'component' => 'box'],
          ],
        ],
        'box' => [
          'inner' => [['uuid' => 'leaf-2', 'component' => 'leaf']],
        ],
      ],
      'props' => [
        's-host' => ['status' => TRUE, 'props' => []],
        'leaf-1' => ['status' => TRUE, 'props' => ['text' => ['value' => 'ONE']]],
        'box' => ['status' => TRUE, 'props' => []],
        'leaf-2' => ['status' => TRUE, 'props' => ['text' => ['value' => 'TWO']]],
      ],
    ];
  }

  /**
   * Converts a stored value against the fixture default.
   */
  private function convert(array $stored, ?array $defaults = NULL): array {
    return ComponentTreeStructure::convertToHybridStorage(
      $defaults ?? $this->defaults(),
      $stored['tree'],
      $stored['props'],
      $this->anchors(),
    );
  }

  /**
   * The region content moves under the default's owner, with its closure.
   */
  public function testRegionContentIsRekeyedToTheDefaultOwner(): void {
    $result = $this->convert($this->stored());

    $this->assertSame('converted', $result['status']);
    $this->assertSame([], $result['tree'][self::ROOT], 'The subset root is empty.');
    $this->assertArrayNotHasKey('s-host', $result['tree'], 'The entity copy of the owner is gone.');
    $this->assertSame(
      ['leaf-1', 'box'],
      array_column($result['tree']['d-host']['body'], 'uuid'),
    );
    $this->assertSame(['leaf-2'], array_column($result['tree']['box']['inner'], 'uuid'));
    $this->assertSame('ONE', $result['props']['leaf-1']['props']['text']['value']);
    $this->assertSame('TWO', $result['props']['leaf-2']['props']['text']['value']);
    $this->assertArrayNotHasKey('s-host', $result['props']);
  }

  /**
   * What the conversion writes is what hybrid mode then renders.
   */
  public function testConvertedValueComposesBackToTheAuthoredContent(): void {
    $result = $this->convert($this->stored());
    $merged = ComponentTreeStructure::composeHybrid($this->defaults(), $result['tree'], $result['props'], $this->anchors());

    $this->assertSame(['d-host'], array_column($merged['tree'][self::ROOT], 'uuid'));
    $this->assertSame(['leaf-1', 'box'], array_column($merged['tree']['d-host']['body'], 'uuid'));
    $this->assertSame([], $merged['orphans']['tree'], 'Nothing is left over as an orphan.');
  }

  /**
   * A region the entity left empty stays empty, rather than re-seeding.
   */
  public function testEmptyStoredRegionIsWrittenEmpty(): void {
    $stored = $this->stored();
    unset($stored['tree']['s-host'], $stored['tree']['box']);
    $result = $this->convert($stored);

    $this->assertSame('converted', $result['status']);
    $this->assertSame([], $result['tree']['d-host']['body']);
  }

  /**
   * A value that is already a hybrid subset is left alone.
   */
  public function testSubsetIsAlreadyConverted(): void {
    $first = $this->convert($this->stored());
    $again = $this->convert(['tree' => $first['tree'], 'props' => $first['props']]);

    $this->assertSame('already', $again['status']);
  }

  /**
   * A different component at the root is not the default's structure.
   */
  public function testDifferentRootComponentIsRefused(): void {
    $stored = $this->stored();
    $stored['tree'][self::ROOT][0]['component'] = 'other';

    $result = $this->convert($stored);
    $this->assertSame('mismatch', $result['status']);
    $this->assertNotEmpty($result['reason']);
  }

  /**
   * An extra component beside the locked one has nowhere to go.
   */
  public function testExtraRootComponentIsRefused(): void {
    $stored = $this->stored();
    $stored['tree'][self::ROOT][] = ['uuid' => 'extra', 'component' => 'leaf'];

    $this->assertSame('mismatch', $this->convert($stored)['status']);
  }

  /**
   * Content in a region that is not customizable would be lost: refused.
   */
  public function testContentInLockedRegionIsRefused(): void {
    $stored = $this->stored();
    $stored['tree']['s-host']['side'] = [['uuid' => 'stray', 'component' => 'leaf']];

    $this->assertSame('mismatch', $this->convert($stored)['status']);
  }

  /**
   * Locked structure that matches the default is walked through.
   */
  public function testMatchingLockedStructureIsFollowed(): void {
    $defaults = [
      'tree' => [
        self::ROOT => [['uuid' => 'd-wrap', 'component' => 'wrap']],
        'd-wrap' => ['main' => [['uuid' => 'd-host', 'component' => 'host']]],
      ],
      'props' => [],
    ];
    $stored = $this->stored();
    $stored['tree'][self::ROOT] = [['uuid' => 's-wrap', 'component' => 'wrap']];
    $stored['tree']['s-wrap'] = ['main' => [['uuid' => 's-host', 'component' => 'host']]];

    $result = $this->convert($stored, $defaults);
    $this->assertSame('converted', $result['status']);
    $this->assertSame(['leaf-1', 'box'], array_column($result['tree']['d-host']['body'], 'uuid'));
    $this->assertArrayNotHasKey('s-wrap', $result['tree']);
  }

}
