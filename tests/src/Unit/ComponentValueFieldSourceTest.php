<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityReferenceValue;
use Drupal\neo_alchemist\Plugin\ComponentValue\EntityValue;
use Drupal\neo_alchemist\Plugin\ComponentValue\HeadingValue;
use Drupal\neo_alchemist\Plugin\ComponentValue\TokenValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers what each value plugin says it reads from the host entity.
 *
 * These declarations are how anything can work out what a layout draws on
 * without rendering it — a layout shared by a whole bundle still shows
 * different text per entity, and the bindings are the only record of where
 * that text comes from.
 *
 * Every case here is a pure function of stored settings, which is the point:
 * the declaration is a static so a caller can ask the plugin class without
 * constructing it, and constructing one would need a shape, a component and a
 * host entity.
 */
#[Group('neo_alchemist')]
final class ComponentValueFieldSourceTest extends UnitTestCase {

  /**
   * Settings, and the field keys the plugin should say it reads.
   */
  public static function sourceProvider(): array {
    return [
      // EntityValue — the plain host-entity read.
      'entity: the configured field' => [
        EntityValue::class,
        ['field' => 'field_body'],
        ['field_body'],
      ],
      'entity: the fallback too, since it shows when the primary is empty' => [
        EntityValue::class,
        ['field' => 'field_body', 'field_fallback' => 'field_summary'],
        ['field_body', 'field_summary'],
      ],
      'entity: a formatter is not a value to read' => [
        EntityValue::class,
        ['field' => 'field_body', 'render_field' => 'field_teaser'],
        ['field_body'],
      ],
      'entity: nothing configured' => [
        EntityValue::class,
        ['field' => '', 'field_fallback' => ''],
        [],
      ],
      'entity: no settings at all' => [EntityValue::class, [], []],

      // EntityReferenceValue — composes the reference path with each child.
      'reference: one mapped child' => [
        EntityReferenceValue::class,
        ['entity' => 'field_ref:entity', 'shape_fields' => ['title' => ['field' => 'name']]],
        ['field_ref:entity.name'],
      ],
      'reference: several mapped children' => [
        EntityReferenceValue::class,
        [
          'entity' => 'field_ref:entity',
          'shape_fields' => [
            'title' => ['field' => 'title'],
            'category' => ['field' => 'field_client'],
          ],
        ],
        ['field_ref:entity.title', 'field_ref:entity.field_client'],
      ],
      'reference: a child mapping nothing' => [
        EntityReferenceValue::class,
        ['entity' => 'field_ref:entity', 'shape_fields' => ['title' => ['field' => '']]],
        [],
      ],
      'reference: no base reference' => [
        EntityReferenceValue::class,
        ['entity' => '', 'shape_fields' => ['title' => ['field' => 'name']]],
        [],
      ],

      // HeadingValue — three independently configurable parts.
      'heading: a part fed from a field' => [
        HeadingValue::class,
        ['title_field' => 'field_headline'],
        ['field_headline'],
      ],
      'heading: every part fed from a field' => [
        HeadingValue::class,
        [
          'supertitle_field' => 'field_kicker',
          'title_field' => 'field_headline',
          'subtitle_field' => 'field_standfirst',
        ],
        ['field_kicker', 'field_headline', 'field_standfirst'],
      ],
      'heading: a part rendered empty reads nothing, whatever it still names' => [
        HeadingValue::class,
        ['title_field' => 'field_headline', 'subtitle_empty' => TRUE, 'subtitle_field' => 'field_sub'],
        ['field_headline'],
      ],
      'heading: the page title is not a field' => [
        HeadingValue::class,
        ['title_page' => TRUE, 'title_field' => ''],
        [],
      ],
      'heading: a literal is not a field' => [
        HeadingValue::class,
        ['title_value' => 'Our Services', 'title_field' => ''],
        [],
      ],

      // TokenValue — field names live inside a token string.
      'token: a field token among literal words' => [
        TokenValue::class,
        ['value' => 'View [term:field_name_singular] Projects'],
        ['field_name_singular'],
      ],
      'token: a token naming a property chain' => [
        TokenValue::class,
        ['value' => '[node:field_ref:title]'],
        ['field_ref:title'],
      ],
      'token: entity metadata is not a field' => [
        TokenValue::class,
        ['value' => '[node:title] — [node:url]'],
        [],
      ],
      'token: two field tokens' => [
        TokenValue::class,
        ['value' => '[node:field_a] and [node:field_b]'],
        ['field_a', 'field_b'],
      ],
      'token: no tokens' => [TokenValue::class, ['value' => 'Just words'], []],
      'token: nothing configured' => [TokenValue::class, ['value' => ''], []],
    ];
  }

  /**
   * A plugin reports exactly the fields a configuration makes it read.
   *
   * @param class-string<\Drupal\neo_alchemist\Value\ComponentValueFieldSourceInterface> $class
   *   The plugin class.
   * @param array $settings
   *   Its stored settings.
   * @param string[] $expected
   *   The field keys it should report.
   */
  #[DataProvider('sourceProvider')]
  public function testSourceFieldKeys(string $class, array $settings, array $expected): void {
    $keys = $class::getSourceFieldKeys($settings);
    sort($keys);
    sort($expected);
    $this->assertSame($expected, $keys);
  }

  /**
   * A plugin never reports the same field twice.
   *
   * Callers de-duplicate anyway, but a plugin repeating itself would inflate
   * any count taken from the declaration.
   */
  public function testKeysAreUnique(): void {
    $keys = EntityReferenceValue::getSourceFieldKeys([
      'entity' => 'field_ref:entity',
      'shape_fields' => [
        'title' => ['field' => 'name'],
        'label' => ['field' => 'name'],
      ],
    ]);
    $this->assertSame(['field_ref:entity.name'], $keys);
  }

}
