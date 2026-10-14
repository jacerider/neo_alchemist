<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * What `drush neo:alchemist:h1-report` checks, and what it finds on a page.
 *
 * Every page of a layout a page can own (custom, hybrid); a sample of a
 * locked one, the most recent first. A page is rendered without the page
 * around it: its H1 is a heading of the layout, or the page title.
 *
 * @see \Drupal\neo_alchemist\PageHeadingReport
 */
#[Group('neo_alchemist')]
class PageHeadingReportTest extends HybridFieldKernelTestBase {

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
    foreach (['na_heading_literal', 'na_heading_tag'] as $id) {
      $this->createFixtureComponent($id);
    }
    $this->container->get('entity_display.repository')
      ->getViewDisplay('entity_test', 'entity_test')
      ->setComponent(static::FIELD_NAME, ['type' => 'neo_component_tree'])
      ->save();
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser([], ['view test entity']);
  }

  /**
   * Which pages: all of a page-owned layout, a recent sample of a locked one.
   */
  public function testPlan(): void {
    $ids = array_map(fn() => (int) $this->createTestEntity()->id(), range(1, 7));

    // The base layout flags a region editable: hybrid, every page.
    $this->assertSame(
      [['hybrid', $ids, 7, FALSE]],
      $this->summary($this->report()->plan('entity_test', 'entity_test')),
    );

    // Locked: the most recent pages stand for the rest.
    $this->config('neo_alchemist.neo_component.na_region_host')->clear('settings.props.body.plugins.body.region_custom')->save();
    $this->resetFieldCaches('na_region_host');
    $recent = array_slice(array_reverse($ids), 0, 3);
    $this->assertSame([['locked', $recent, 7, TRUE]], $this->summary($this->report()->plan('entity_test', 'entity_test', sample: 3)));
    $this->assertSame([['locked', $ids, 7, FALSE]], $this->summary($this->report()->plan('entity_test', 'entity_test', all: TRUE)));
    $capped = $this->report()->plan('entity_test', 'entity_test', sample: 3, limit: 2);
    $this->assertSame([['locked', array_slice($recent, 0, 2), 7, TRUE]], $this->summary($capped), 'The limit caps a sample too.');

    // Custom: every page, the limit aside.
    $this->customLayout();
    $this->assertSame([['custom', array_slice($ids, 0, 4), 7, FALSE]], $this->summary($this->report()->plan('entity_test', 'entity_test', limit: 4)));

    $this->assertSame([], $this->report()->plan('entity_test', 'no_such_bundle'));
  }

  /**
   * A heading that prints its tag is the H1, found in its component.
   */
  public function testHeadingIsTheH1(): void {
    $this->customLayout();
    $row = $this->report()->row($this->entityWithTree(['first' => ['na_heading_tag', $this->headingProps('Welcome')]]));
    $this->assertSame('Welcome', $row['h1']);
    $this->assertSame('neo_alchemist_test:na_heading_tag', $row['source']);
    $this->assertSame('1', $row['count']);
    $this->assertSame('ok', $row['status']);
    $this->assertStringStartsWith('/entity_test/', $row['path']);
  }

  /**
   * Without a heading that can be the H1, the page title is.
   *
   * An empty heading cannot be it, nor one whose template types its own
   * `<h2>`. One that types `<h1>` is a second H1 beside the page title.
   */
  public function testPageTitleIsTheH1(): void {
    $this->customLayout();
    foreach (['na_heading_tag' => '', 'na_heading_literal' => 'Typed h2'] as $component => $title) {
      $row = $this->report()->row($this->entityWithTree(['first' => [$component, $this->headingProps($title)]], 'Our page'));
      $this->assertSame(['Our page', 'page title', '1'], [$row['h1'], $row['source'], $row['count']], $component);
    }

    $this->createFixtureComponent('na_heading_h1');
    $typed = $this->report()->row($this->entityWithTree(['typed' => ['na_heading_h1', $this->headingProps('Typed h1')]], 'Our page'));
    $this->assertSame('2', $typed['count']);
    $this->assertSame('Our page | Typed h1', $typed['h1']);
    $this->assertSame('page title | neo_alchemist_test:na_heading_h1', $typed['source']);
  }

  /**
   * The report.
   */
  private function report() {
    return $this->container->get('neo_alchemist.page_heading_report');
  }

  /**
   * A plan's groups, as [layout, ids, total, sampled].
   */
  private function summary(array $plan): array {
    return array_map(fn(array $group) => [
      $group['layout'],
      array_map('intval', $group['ids']),
      $group['total'],
      $group['sampled'],
    ], $plan);
  }

  /**
   * Lets each page own its layout.
   */
  private function customLayout(): void {
    $field = FieldConfig::loadByName('entity_test', 'entity_test', static::FIELD_NAME);
    $field->setSetting('allow_custom', TRUE);
    $field->setSetting('defaults', []);
    $field->save();
    $this->resetFieldCaches('na_heading_tag');
  }

  /**
   * An entity whose tree holds the given root components, in order.
   *
   * @param array<string, array{0: string, 1: array}> $components
   *   Component id and props entry, keyed by instance uuid.
   * @param string $label
   *   The entity's name: its page title.
   */
  private function entityWithTree(array $components, string $label = 'A page'): EntityTest {
    $tree = [];
    $props = [];
    foreach ($components as $uuid => [$component, $entry]) {
      $tree[ComponentTreeStructure::ROOT_UUID][] = ['uuid' => $uuid, 'component' => $component];
      $props[$uuid] = $entry;
    }
    $entity = $this->createTestEntity();
    $entity->set('name', $label);
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
