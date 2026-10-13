<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins token modifiers on query rows: they read the row, and render as HTML.
 *
 * A children-match provider (here entity_query) fills each row of a list from
 * an entity, and a site builder can attach value plugins to a row child, such
 * as a token template composing a sentence from the row's fields. Two things
 * stopped that working:
 *
 * - Tokens resolved against the component's host entity. Every shape's
 *   getEntity() is the host, and a row child never learned which entity its row
 *   came from, so on a page listing rooms `[node:title]` was the page's title
 *   (or nothing), never the room's. The mapper now records each row's entity in
 *   ChildShapeState and a shape resolves its context entity through it.
 * - On a markup child, formatted_text (weight 10) ran before token (11): it
 *   rendered first, and the token modifier then replaced the rendered markup
 *   with its plain-text template, which printed escaped. formatted_text now
 *   runs last.
 *
 * Red/green proof performed during development: with the token trait reading
 * getEntity() again, testRowTokensReadTheRowEntity and
 * testMarkupRowTokensRenderHtml go red (the host's empty title); with
 * formatted_text back at weight 10, testMarkupRowTokensRenderHtml goes red
 * (the body is a plain string, which Twig prints escaped).
 *
 * @see \Drupal\neo_alchemist\Shape\ChildShapeState::setRowEntity()
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginBase::getContextEntity()
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\FormattedTextValue
 */
#[Group('neo_alchemist')]
class RowContextTokenTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['filter', 'node']);
    NodeType::create(['type' => 'room', 'name' => 'Room'])->save();
    // A format with no filters passes markup through untouched, so the test
    // sees exactly what the token template produced.
    FilterFormat::create(['format' => 'full', 'name' => 'Full', 'filters' => []])->save();
    foreach (['Beta', 'Alpha'] as $title) {
      Node::create(['type' => 'room', 'title' => $title, 'status' => 1])->save();
    }
  }

  /**
   * Builds the fixture with its rows queried from the room nodes.
   */
  private function buildComponent(): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $component = Component::create([
      'label' => 'Row token fixture',
      'description' => 'Row token fixture',
      'component' => 'neo_alchemist_test:na_row_token',
      'status' => TRUE,
      'target_entity_type' => 'node',
    ]);
    $component->save();
    $id = $component->id();

    $token = fn (string $template) => [
      'token' => ['plugin_id' => 'token', 'status' => TRUE, 'settings' => ['value' => $template]],
    ];
    $formatted = [
      'formatted_text' => ['plugin_id' => 'formatted_text', 'status' => TRUE, 'settings' => ['format' => 'full']],
    ];
    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.' . $id)
      ->set('settings.props.items.plugins.items', [
        'entity_query' => [
          'id' => 'entity_query',
          'settings' => [
            'entity_type' => 'node',
            'bundle' => 'room',
            'length' => 10,
            'sort_field' => 'title',
            'sort_direction' => 'ASC',
            'shape_fields' => [
              'label' => ['field' => 'title', 'plugins' => $token('Room: [node:title]')],
              'body' => [
                'field' => 'title',
                'plugins' => $token('<p><strong>[node:title]</strong> seats guests.</p>') + $formatted,
              ],
            ],
            'shape_published' => TRUE,
            'processing_mode' => 'block',
          ],
        ],
      ])
      ->save();
    $storage->resetCache([$id]);
    /** @var \Drupal\neo_alchemist\Entity\Component $component */
    $component = $storage->load($id);
    return $component;
  }

  /**
   * Resolves the fixture's items, inside a render context.
   *
   * The formatted_text modifier renders its value, which needs one.
   */
  private function items(): array {
    $component = $this->buildComponent();
    return $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      fn () => $component->getPropValues()['items'] ?? [],
    );
  }

  /**
   * A token on a row child resolves against that row's entity.
   */
  public function testRowTokensReadTheRowEntity(): void {
    $labels = array_map(fn ($row) => (string) $row['label'], $this->items());
    $this->assertSame(['Room: Alpha', 'Room: Beta'], $labels);
  }

  /**
   * A token template on a markup row child renders its HTML.
   */
  public function testMarkupRowTokensRenderHtml(): void {
    $body = $this->items()[0]['body'] ?? NULL;
    // Twig escapes a plain string and prints markup as-is, so the type is what
    // decides whether the tags show as tags or as text.
    $this->assertInstanceOf(MarkupInterface::class, $body, 'The body reaches Twig as markup, not a string it would escape.');
    $this->assertStringContainsString('<strong>Alpha</strong>', (string) $body);
  }

  /**
   * A row child's context entity is its row; the list's own is the host.
   */
  public function testContextEntityFollowsTheRow(): void {
    $items = $this->buildComponent()->getPropShape('items');
    $children = [];
    foreach ($items->getChildShapes(1) as $child) {
      $children[$child->getName()] = $child;
    }
    $this->assertSame('Beta', $children['label']->getContextEntity()->label());
    $this->assertTrue($items->getContextEntity()->isNew(), 'Outside a row, the context is the host placeholder.');
  }

}
