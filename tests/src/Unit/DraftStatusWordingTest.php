<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * What the band's status line says, and when it is allowed to say "saved".
 *
 * The line sits beside the Save button, and ::buildDynamicOperations() offers
 * Save on exactly `hasDraft() || !isDefaultRevision()`. So the green "All
 * changes saved" has to mean "Save is not being offered" — anything else puts
 * a reassurance next to a button insisting there is work outstanding.
 *
 * The defect this pins the fix for: the line asked only `hasDraft()`. On a
 * content-moderated node the layout is saved into a forward revision that is
 * not the default one, which is routine — and the line read "All changes
 * saved" beside a Save button that would not go away. Editors read the button
 * as broken and pressed it again and again; what it wanted was the workflow
 * state in the publish dialog moved to Published.
 *
 * @see \Drupal\neo_alchemist\ComponentManageHelper::buildDraftStatus()
 * @see \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem::access()
 */
#[Group('neo_alchemist')]
class DraftStatusWordingTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * Draft, default revision, expected wording, expected dot.
   *
   * @return array<string, array{bool, bool, string, string}>
   *   The cases.
   */
  public static function states(): array {
    return [
      'nothing unsaved, revision is live' => [
        FALSE, TRUE, 'All changes saved', 'bg-success-500',
      ],
      'a shared draft is open' => [
        TRUE, TRUE, 'Unsaved changes', 'bg-warning-500',
      ],
      // The case the fix is for.
      'saved into a revision that is not live' => [
        FALSE, FALSE, 'Saved, not published', 'bg-warning-500',
      ],
      // A draft outranks the revision state: there is something unsaved, and
      // that is the more urgent of the two things to say.
      'a draft on a revision that is not live' => [
        TRUE, FALSE, 'Unsaved changes', 'bg-warning-500',
      ],
    ];
  }

  /**
   * The line reports being saved only when nothing is waiting to be published.
   */
  #[DataProvider('states')]
  public function testWording(bool $hasDraft, bool $isDefaultRevision, string $expected, string $dot): void {
    $build = ComponentManageHelper::buildDraftStatus($this->item($hasDraft, $isDefaultRevision));

    $this->assertSame($expected, (string) $build['status']['label']['#plain_text']);
    $this->assertContains($dot, $build['status']['dot']['#attributes']['class']);
  }

  /**
   * A non-revisionable entity can never be "not published".
   *
   * The entity types this field rides on are not all revisionable, and the
   * check has to read as "not live", not as "unknown". Without the interface
   * test, a plain entity answers nothing and the line would have to guess.
   */
  public function testNonRevisionableEntityReadsAsSaved(): void {
    $item = $this->createMock(ComponentTreeItem::class);
    $item->method('hasDraft')->willReturn(FALSE);
    $item->method('draftLastEditor')->willReturn(NULL);
    $item->method('getEntity')->willReturn($this->createMock(EntityInterface::class));

    $build = ComponentManageHelper::buildDraftStatus($item);

    $this->assertSame('All changes saved', (string) $build['status']['label']['#plain_text']);
  }

  /**
   * A tree item whose draft and revision state answer as asked.
   *
   * The last editor is NULL so buildDraftPresence() returns early: who else
   * is in the draft is a different question, pinned elsewhere.
   */
  private function item(bool $hasDraft, bool $isDefaultRevision): ComponentTreeItem {
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('isDefaultRevision')->willReturn($isDefaultRevision);

    $item = $this->createMock(ComponentTreeItem::class);
    $item->method('hasDraft')->willReturn($hasDraft);
    $item->method('draftLastEditor')->willReturn(NULL);
    $item->method('getEntity')->willReturn($entity);

    return $item;
  }

}
