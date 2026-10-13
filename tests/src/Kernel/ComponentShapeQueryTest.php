<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use PHPUnit\Framework\Attributes\Group;

/**
 * A query over a tree's shapes walks the whole tree, in reading order.
 *
 * Nested components are reached, a component's own props come before its
 * children's, an array prop gives its items' shapes (not the template that
 * holds no value), and unpublished components are passed over. The
 * [neo:image] token relies on this to find the first image a page shows.
 *
 * @see neo_alchemist_neo_token_image_alter()
 */
#[Group('neo_alchemist')]
class ComponentShapeQueryTest extends HybridFieldKernelTestBase {

  /**
   * {@inheritdoc}
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
    foreach (['na_heading_tag', 'na_array_heading'] as $id) {
      $this->createFixtureComponent($id);
    }
    $field = FieldConfig::loadByName('entity_test', 'entity_test', static::FIELD_NAME);
    $field->setSetting('allow_custom', TRUE);
    $field->setSetting('defaults', []);
    $field->save();
    $this->resetFieldCaches('na_array_heading');
  }

  /**
   * Every heading of the tree, in the order a reader meets them.
   */
  public function testWalksTheWholeTreeInReadingOrder(): void {
    $hidden = $this->heading('Hidden');
    $hidden['status'] = FALSE;
    $entity = $this->entityWithTree([
      ComponentTreeStructure::ROOT_UUID => [
        ['uuid' => 'list', 'component' => 'na_array_heading'],
        ['uuid' => 'section', 'component' => 'na_heading_tag'],
        ['uuid' => 'hidden', 'component' => 'na_heading_tag'],
      ],
      'section' => ['body' => [['uuid' => 'child', 'component' => 'na_heading_tag']]],
    ], [
      'list' => [
        'status' => TRUE,
        'props' => [
          'items' => [
            'ref' => 'array',
            'value' => [
              ['heading' => ['title' => ['value' => 'Item one']]],
              ['heading' => ['title' => ['value' => 'Item two']]],
            ],
          ],
        ],
      ],
      'section' => $this->heading('Section'),
      'child' => $this->heading('Child'),
      'hidden' => $hidden,
    ]);

    $shapes = $entity->get(static::FIELD_NAME)->getQuery()->filterByRef('heading')->filterNonEmpty(FALSE)->execute();
    $titles = array_map(fn($shape) => $shape->getValue()['title'] ?? NULL, $shapes);
    $this->assertSame(['Item one', 'Item two', 'Section', 'Child'], $titles);
  }

  /**
   * A props entry whose heading has the given title.
   */
  private function heading(string $title): array {
    return [
      'status' => TRUE,
      'props' => [
        'heading' => ['ref' => 'heading', 'value' => ['title' => ['value' => $title]]],
      ],
    ];
  }

  /**
   * Creates an entity with the given tree and props.
   */
  private function entityWithTree(array $tree, array $props): EntityTest {
    $entity = $this->createTestEntity();
    $entity->set(static::FIELD_NAME, [['tree' => $tree, 'props' => $props]]);
    $entity->save();
    return $this->reloadEntity($entity);
  }

}
