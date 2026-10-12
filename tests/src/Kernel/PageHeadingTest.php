<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * A page's layout supplies its one H1: its first heading that can be one.
 *
 * A heading can be the H1 when it has text, its prop does not opt out
 * (`h1: false`), and its template prints the tag it is handed. Only the tree
 * a full view mode renders takes part.
 *
 * @see \Drupal\neo_alchemist\PageHeading
 */
#[Group('neo_alchemist')]
class PageHeadingTest extends HybridFieldKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The heading's `size` sub-prop is backed by `list_string`, from options.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'entity_test',
    'neo_icon',
    'neo_tooltip',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['na_heading_literal', 'na_heading_tag', 'na_heading_optout'] as $id) {
      $this->createFixtureComponent($id);
    }
    // Custom mode, so each entity owns its own tree.
    $field = FieldConfig::loadByName('entity_test', 'entity_test', static::FIELD_NAME);
    $field->setSetting('allow_custom', TRUE);
    $field->setSetting('defaults', []);
    $field->save();
    $this->container->get('entity_display.repository')
      ->getViewDisplay('entity_test', 'entity_test')
      ->setComponent(static::FIELD_NAME, ['type' => 'neo_component_tree'])
      ->save();
    $this->resetFieldCaches('na_heading_tag');
    // Components take their view access from the host entity.
    $this->setUpCurrentUser([], ['view test entity']);
  }

  /**
   * The first heading that prints its tag is the H1; the rest are h2.
   *
   * A heading whose template types its own tag cannot be handed the H1, so it
   * is passed over even though it comes first.
   */
  public function testFirstQualifyingHeadingIsTheH1(): void {
    $entity = $this->entityWithTree([
      'literal' => ['na_heading_literal', $this->headingProps('Literal')],
      'first' => ['na_heading_tag', $this->headingProps('First')],
      'second' => ['na_heading_tag', $this->headingProps('Second')],
    ]);
    $html = $this->renderEntity($entity);
    $this->assertSame(['First'], $this->headings($html, 'h1'));
    $this->assertSame(['Literal', 'Second'], $this->headings($html, 'h2'));
  }

  /**
   * Opted-out, empty and hidden headings are passed over.
   *
   * The opted-out heading renders its declared fallback, h3.
   */
  public function testIneligibleHeadingsArePassedOver(): void {
    $hidden = $this->headingProps('Hidden');
    $hidden['status'] = FALSE;
    $entity = $this->entityWithTree([
      'optout' => ['na_heading_optout', $this->headingProps('Opted out')],
      'empty' => ['na_heading_tag', $this->headingProps('')],
      'hidden' => ['na_heading_tag', $hidden],
      'shown' => ['na_heading_tag', $this->headingProps('Shown')],
    ]);
    $html = $this->renderEntity($entity);
    $this->assertSame(['Shown'], $this->headings($html, 'h1'));
    $this->assertSame(['Opted out'], $this->headings($html, 'h3'));
    $this->assertStringNotContainsString('Hidden', $html);
  }

  /**
   * A component's own heading comes before its children's.
   */
  public function testParentHeadingComesBeforeItsChildren(): void {
    $html = $this->renderEntity($this->entityWithTree(
      ['parent' => ['na_heading_tag', $this->headingProps('Parent')]],
      ['parent' => ['child' => ['na_heading_tag', $this->headingProps('Child')]]],
    ));
    $this->assertSame(['Parent'], $this->headings($html, 'h1'));
    $this->assertSame(['Child'], $this->headings($html, 'h2'));

    // Without a heading of its own, its first child's is the H1.
    $html = $this->renderEntity($this->entityWithTree(
      ['parent' => ['na_heading_tag', $this->headingProps('')]],
      ['parent' => ['child' => ['na_heading_tag', $this->headingProps('Child')]]],
    ));
    $this->assertSame(['Child'], $this->headings($html, 'h1'));
  }

  /**
   * Only the full view mode is a page's main content.
   *
   * Another view mode renders the same tree — here through the same default
   * display — as part of some other page, which has an H1 of its own.
   */
  public function testOnlyTheFullViewModeHasAnH1(): void {
    $entity = $this->entityWithTree([
      'first' => ['na_heading_tag', $this->headingProps('First')],
    ]);
    $html = $this->renderEntity($entity, 'teaser');
    $this->assertSame([], $this->headings($html, 'h1'));
    $this->assertSame(['First'], $this->headings($html, 'h2'));

    $pageHeading = $this->container->get('neo_alchemist.page_heading');
    $item = $entity->get(static::FIELD_NAME)->first();
    $this->assertTrue($pageHeading->isPageField($item), 'The full view mode renders the field, so its preview is page content.');
    $this->assertNull($pageHeading->claim($item), 'A tree not rendered as page content claims nothing.');
  }

  /**
   * Renders an entity and returns its markup.
   */
  private function renderEntity(EntityTest $entity, string $viewMode = 'full'): string {
    $build = $this->container->get('entity_type.manager')->getViewBuilder('entity_test')->view($entity, $viewMode);
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * The text of every heading of a level, in order.
   *
   * @return string[]
   *   The headings' text.
   */
  private function headings(string $html, string $tag): array {
    preg_match_all('#<' . $tag . '>(.*?)</' . $tag . '>#s', $html, $matches);
    return array_map('trim', $matches[1]);
  }

  /**
   * Creates an entity whose tree holds the given components, in order.
   *
   * @param array<string, array{0: string, 1: array}> $components
   *   Root components: component id and props entry, keyed by instance uuid.
   * @param array<string, array<string, array{0: string, 1: array}>> $children
   *   Components in a parent's `body` region, keyed by the parent's uuid.
   */
  private function entityWithTree(array $components, array $children = []): EntityTest {
    $tree = [];
    $props = [];
    foreach ($components as $uuid => [$component, $entry]) {
      $tree[ComponentTreeStructure::ROOT_UUID][] = ['uuid' => $uuid, 'component' => $component];
      $props[$uuid] = $entry;
    }
    foreach ($children as $parent => $parentChildren) {
      foreach ($parentChildren as $uuid => [$component, $entry]) {
        $tree[$parent]['body'][] = ['uuid' => $uuid, 'component' => $component];
        $props[$uuid] = $entry;
      }
    }
    $entity = $this->createTestEntity();
    $entity->set(static::FIELD_NAME, [['tree' => $tree, 'props' => $props]]);
    $entity->save();
    return $this->reloadEntity($entity);
  }

  /**
   * A props entry whose heading has the given title.
   */
  private function headingProps(string $title): array {
    return [
      'status' => TRUE,
      'props' => [
        'heading' => [
          'ref' => 'heading',
          'value' => $title === '' ? [] : ['title' => ['value' => $title]],
        ],
      ],
    ];
  }

}
