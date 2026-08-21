<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\EntityTypeSelectBuilder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The one entity-type/bundle select builder the three provider forms share.
 *
 * EntityLoadValue, EntityQueryValue and EntitySlot built these selects three
 * near-identical times; this pins the single builder they now call, including
 * the two axes that legitimately vary between them — content-only versus all
 * entity types, and the $overrides a caller merges over the defaults.
 *
 * @see \Drupal\neo_alchemist\EntityTypeSelectBuilder
 */
#[Group('neo_alchemist')]
class EntityTypeSelectBuilderTest extends UnitTestCase {

  /**
   * An entity type manager whose definitions are one content, one config type.
   */
  private function entityTypeManager(): EntityTypeManagerInterface {
    $content = $this->createMock(ContentEntityTypeInterface::class);
    $content->method('id')->willReturn('node');
    $content->method('getLabel')->willReturn('Node');
    // A plain entity type — not a ContentEntityTypeInterface — stands in for a
    // config entity type, which the content-only filter must exclude.
    $config = $this->createMock(EntityTypeInterface::class);
    $config->method('id')->willReturn('view');
    $config->method('getLabel')->willReturn('View');

    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getDefinitions')->willReturn(['node' => $content, 'view' => $config]);
    return $manager;
  }

  /**
   * The content-only filter keeps config entity types out of the options.
   */
  public function testEntityTypeSelectContentOnly(): void {
    $element = EntityTypeSelectBuilder::entityTypeSelect(
      $this->entityTypeManager(),
      'node',
      ['callback' => ['Foo', 'refreshAjax'], 'wrapper' => 'wrap'],
      TRUE,
      ['#description' => 'Scope it.'],
    );

    $this->assertSame('select', $element['#type']);
    $this->assertSame(['node' => 'Node'], $element['#options'], 'The config entity type is filtered out.');
    $this->assertSame('node', $element['#default_value']);
    $this->assertTrue($element['#required']);
    $this->assertSame(['callback' => ['Foo', 'refreshAjax'], 'wrapper' => 'wrap'], $element['#ajax']);
    $this->assertSame('Scope it.', $element['#description'], 'An override key is merged in.');
    $this->assertInstanceOf(TranslatableMarkup::class, $element['#title']);
    $this->assertSame('Entity type', $element['#title']->getUntranslatedString());
  }

  /**
   * Without the content-only filter, every entity type is offered, sorted.
   */
  public function testEntityTypeSelectAllTypesSorted(): void {
    $element = EntityTypeSelectBuilder::entityTypeSelect(
      $this->entityTypeManager(),
      '',
      [],
      FALSE,
    );

    $this->assertSame(['node' => 'Node', 'view' => 'View'], $element['#options']);
    $this->assertArrayNotHasKey('#description', $element, 'No override, no description.');
  }

  /**
   * The bundle select lists sorted bundle labels and honours its overrides.
   */
  public function testBundleSelectOptionsAndOverrides(): void {
    $bundleInfo = $this->createMock(EntityTypeBundleInfoInterface::class);
    $bundleInfo->method('getBundleInfo')->with('node')->willReturn([
      'page' => ['label' => 'Basic page'],
      'article' => ['label' => 'Article'],
    ]);

    $element = EntityTypeSelectBuilder::bundleSelect(
      $bundleInfo,
      'node',
      ['article'],
      ['callback' => ['Foo', 'refreshAjax'], 'wrapper' => 'wrap'],
      ['#title' => 'Bundles', '#multiple' => TRUE, '#required' => TRUE],
    );

    $this->assertSame('select', $element['#type']);
    $this->assertSame(['article' => 'Article', 'page' => 'Basic page'], $element['#options'], 'Labels are sorted.');
    $this->assertSame(['article'], $element['#default_value']);
    $this->assertTrue($element['#multiple'], 'The multiple override is applied.');
    $this->assertTrue($element['#required']);
    $this->assertSame('Bundles', (string) $element['#title']);
  }

}
