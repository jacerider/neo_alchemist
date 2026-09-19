<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use PHPUnit\Framework\Attributes\Group;

/**
 * [neo:description] falls back to the first rich text of the component tree.
 *
 * neo_alchemist_neo_token_description_alter() describes an entity by the
 * first filled rich-text prop of a published component, in reading order,
 * cut at 160 characters. It must never describe a page by a component's
 * example text — the value an unset prop reports.
 *
 * @see neo_alchemist_neo_token_description_alter()
 */
#[Group('neo_alchemist')]
class DescriptionFromContentTest extends HybridFieldKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The rich-text shape's formatted_text plugin needs text and filter.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'entity_test',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createFixtureComponent('na_markup');
    // Custom mode, so each entity owns its own tree.
    $field = FieldConfig::loadByName('entity_test', 'entity_test', static::FIELD_NAME);
    $field->setSetting('allow_custom', TRUE);
    $field->setSetting('defaults', []);
    $field->save();
    // The token reads the tree the entity's default display renders.
    $this->container->get('entity_display.repository')
      ->getViewDisplay('entity_test', 'entity_test')
      ->setComponent(static::FIELD_NAME, ['type' => 'neo_component_tree'])
      ->save();
    $this->resetFieldCaches('na_markup');
  }

  /**
   * The first rich text describes the page; blocks read as separate words.
   */
  public function testFirstRichTextDescribesThePage(): void {
    $entity = $this->entityWithTree([
      'leaf' => ['na_leaf', $this->leafProps('PLAIN STRING')],
      'first' => ['na_markup', $this->markupProps('<h2>Our Services</h2><p>Fast &amp; fair.</p>')],
      'second' => ['na_markup', $this->markupProps('<p>Later text.</p>')],
    ]);
    $this->assertSame('Our Services Fast & fair.', $this->describe($entity));
  }

  /**
   * Hidden components and unset props are passed over.
   *
   * An unset rich-text prop reports the component's example, which must not
   * describe the page.
   */
  public function testHiddenComponentsAndUnsetPropsAreSkipped(): void {
    $hidden = $this->markupProps('<p>Hidden text.</p>');
    $hidden['status'] = FALSE;
    $unset = ['status' => TRUE, 'props' => ['title' => ['ref' => 'string', 'value' => ['value' => 'Title only']]]];
    $entity = $this->entityWithTree([
      'hidden' => ['na_markup', $hidden],
      'unset' => ['na_markup', $unset],
      'shown' => ['na_markup', $this->markupProps('<p>Shown text.</p>')],
    ]);
    $this->assertSame('Shown text.', $this->describe($entity));
  }

  /**
   * Long text is cut at 160 characters on a word boundary.
   */
  public function testLongTextIsCutOnAWord(): void {
    $words = trim(str_repeat('furnace repair ', 20));
    $entity = $this->entityWithTree([
      'long' => ['na_markup', $this->markupProps("<p>$words</p>")],
    ]);
    $description = $this->describe($entity);
    $this->assertLessThanOrEqual(160, mb_strlen($description));
    $this->assertStringEndsWith('…', $description);
    $this->assertStringStartsWith(rtrim(mb_substr($description, 0, -1)), $words);
    $this->assertMatchesRegularExpression('/(furnace|repair)…$/', $description, 'Cut between words, not inside one.');
  }

  /**
   * A description already given is kept, and the setting turns this off.
   */
  public function testGivenDescriptionAndSettingAreRespected(): void {
    $entity = $this->entityWithTree([
      'first' => ['na_markup', $this->markupProps('<p>Content text.</p>')],
    ]);
    $this->assertSame('Given', $this->describe($entity, 'Given'));

    $this->config('neo_alchemist.settings')->set('description_from_content', FALSE)->save();
    $this->assertNull($this->describe($entity));
  }

  /**
   * Runs the description alter the way neo's token does.
   */
  private function describe(ContentEntityInterface $entity, ?string $description = NULL): ?string {
    $params = [];
    $this->container->get('module_handler')->alter('neo_token_description', $description, $params, $entity);
    return $description;
  }

  /**
   * Creates an entity whose tree holds the given components, in order.
   *
   * @param array<string, array{0: string, 1: array}> $components
   *   Component id and props entry, keyed by instance uuid.
   */
  private function entityWithTree(array $components): ContentEntityInterface {
    $tree = [];
    $props = [];
    foreach ($components as $uuid => [$component, $entry]) {
      $tree[] = ['uuid' => $uuid, 'component' => $component];
      $props[$uuid] = $entry;
    }
    $entity = $this->createTestEntity();
    $entity->set(static::FIELD_NAME, [
      ['tree' => [ComponentTreeStructure::ROOT_UUID => $tree], 'props' => $props],
    ]);
    $entity->save();
    return $this->reloadEntity($entity);
  }

  /**
   * A na_markup props entry with its body set.
   */
  private function markupProps(string $html): array {
    return [
      'status' => TRUE,
      'props' => [
        'body' => ['ref' => 'markup', 'value' => ['value' => $html]],
      ],
    ];
  }

}
