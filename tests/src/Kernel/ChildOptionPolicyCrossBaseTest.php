<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist\Entity\Component;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that both children-bearing shape bases honour the same child options.
 *
 * A children-match producer walks the mapping a site builder configured and
 * flags each child it was told nothing about as hidden, keyed by child shape
 * id. Consuming those flags was the job of exactly one of the two bases that
 * build children: ChildrenShapeBase::initChildShape() read them;
 * StructuredObjectShapeBase built its children through a different routine and
 * read none of them. So identical configuration behaved differently depending
 * on which base a shape happened to extend, and nothing warned.
 *
 * Symptom: a site builder attaches a producer to a link prop and maps only the
 * URI, leaving the title unmapped — the configuration form even labels it "Not
 * mapped". On an object prop the unmapped child renders nothing, as asked. On a
 * link prop the flag landed in an array nobody read, the schema examples
 * backfilled the gap, and the component author's placeholder text rendered on
 * the live page in its place.
 *
 * This is the comparison no test made, which is why the divergence shipped. One
 * producer configuration is applied to a prop of each base — `box` is an
 * ObjectShape (ChildrenShapeBase), `link` is a LinkShape
 * (StructuredObjectShapeBase) — and the resolved values are asserted to agree.
 *
 * @see \Drupal\neo_alchemist\Shape\ChildOptionPolicy
 */
#[Group('neo_alchemist')]
class ChildOptionPolicyCrossBaseTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'options',
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
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');
  }

  /**
   * Builds the fixture with one mapped and one unmapped child on each base.
   *
   * `entity_load` is the provider used because it is applicable to any object
   * prop — the query and reference providers require an iterable or expandable
   * shape, which rules a link prop out before the mapping is ever read.
   *
   * The mapped child is bound to a literal rather than to an entity field so
   * both props take the same source and neither depends on a field type.
   *
   * @return \Drupal\neo_alchemist\Entity\Component
   *   The reloaded component.
   */
  private function buildComponent(): Component {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_component');
    $component = Component::create([
      'label' => 'Cross-base child option fixture',
      'description' => 'Cross-base child option fixture',
      'component' => 'neo_alchemist_test:na_falsy_object',
      'status' => TRUE,
      'target_entity_type' => 'entity_test',
    ]);
    $component->save();
    $id = $component->id();

    $this->container->get('config.factory')
      ->getEditable('neo_alchemist.neo_component.' . $id)
      ->set('settings.props.box.plugins.box', [
        'entity_load' => [
          'id' => 'entity_load',
          'settings' => [
            'entity_type' => 'entity_test',
            'entity_id' => 1,
            'shape_fields' => [
              'text' => ['field' => '_raw:string', 'string' => 'MAPPED TEXT'],
              'note' => ['field' => ''],
            ],
          ],
        ],
      ])
      ->set('settings.props.link.plugins.link', [
        'entity_load' => [
          'id' => 'entity_load',
          'settings' => [
            'entity_type' => 'entity_test',
            'entity_id' => 1,
            'shape_fields' => [
              'uri' => ['field' => '_raw:string', 'string' => 'https://mapped.example.com'],
              'title' => ['field' => ''],
            ],
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
   * The values that reach the rendered component.
   *
   * Read through getPropValues() rather than getDefaultValue(): the latter is
   * the producer's own output, which carries the unmapped child as an empty
   * marker on both bases and so agrees even while the rendered pages disagree.
   * What the child options decide is what happens to that marker afterwards.
   *
   * @return array
   *   The prop values.
   */
  private function propValues(): array {
    EntityTest::create(['name' => 'ENTITY NAME'])->save();
    return $this->buildComponent()->getPropValues();
  }

  /**
   * The mapped children resolve on both bases.
   *
   * The control. Without it, the assertions below would also pass against a
   * producer that resolved nothing at all.
   */
  public function testMappedChildrenResolveOnBothBases(): void {
    $values = $this->propValues();

    $this->assertSame('MAPPED TEXT', $values['box']['text'] ?? NULL);
    $this->assertSame('https://mapped.example.com', $values['link']['uri'] ?? NULL);
  }

  /**
   * An unmapped child of an object prop renders nothing, not its example.
   *
   * The base that already consumed the flag. Pins the behaviour the other has
   * to match.
   */
  public function testUnmappedObjectChildIsHidden(): void {
    $values = $this->propValues();

    $this->assertEmpty(
      $values['box']['note'] ?? NULL,
      'A child the producer left unmapped is flagged hidden, so it must resolve to nothing rather than to the component author\'s example.',
    );
  }

  /**
   * An unmapped child of a link prop renders nothing, not its example.
   *
   * The defect. Same configuration, same flag, different base — and before the
   * policy collaborator this resolved to the fixture's `EXAMPLE LINK TITLE`,
   * which is placeholder content reaching a visitor.
   */
  public function testUnmappedStructuredChildIsHidden(): void {
    $values = $this->propValues();

    $this->assertEmpty(
      $values['link']['title'] ?? NULL,
      'A hidden child must be hidden whichever base its parent shape extends; the configuration a site builder set is not the shape base\'s to discard.',
    );
  }

  /**
   * Both bases agree, stated as one assertion.
   *
   * The two above pin each base against the fixture. This pins them against
   * each other, so a change that regresses both together still fails.
   */
  public function testBothBasesAgreeOnTheUnmappedChild(): void {
    $values = $this->propValues();

    $this->assertSame(
      empty($values['box']['note']),
      empty($values['link']['title']),
      'One producer configuration, two shape bases, one behaviour.',
    );
  }

}
