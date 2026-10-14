<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit\Audit;

use Drupal\Component\Utility\Html;
use Drupal\neo_alchemist\Audit\Check\EmptyElementsCheck;
use Drupal\neo_alchemist\Audit\Check\HeadingLevelsCheck;
use Drupal\neo_alchemist\Audit\Check\ImageAltCheck;
use Drupal\neo_alchemist\Audit\ComponentAuditCheckInterface;
use Drupal\neo_alchemist\Audit\ComponentAuditContext;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * What each component audit check flags in a component's markup.
 *
 * @see \Drupal\neo_alchemist\Audit\ComponentAudit
 */
#[Group('neo_alchemist')]
class ComponentAuditChecksTest extends UnitTestCase {

  /**
   * Each check flags the elements it should, and only those.
   *
   * @param string $class
   *   The check class.
   * @param string $html
   *   The component's markup.
   * @param string[] $flagged
   *   The text or src of each flagged element, in order.
   */
  #[DataProvider('cases')]
  public function testCheck(string $class, string $html, array $flagged): void {
    $check = new $class();
    $check->setStringTranslation($this->getStringTranslationStub());
    $this->assertInstanceOf(ComponentAuditCheckInterface::class, $check);
    $context = new ComponentAuditContext('test:probe', 'examples', $this->createMock(ComponentInterface::class));
    $issues = $check->check(Html::load($html), $context);
    $this->assertSame($flagged, array_map(
      fn(array $issue) => trim($issue['element']->textContent) ?: $issue['element']->getAttribute('src'),
      $issues,
    ));
    foreach ($issues as $issue) {
      $this->assertNotSame('', $issue['message']);
    }
  }

  /**
   * Markup, and what each check makes of it.
   */
  public static function cases(): array {
    return [
      'headings in order' => [
        HeadingLevelsCheck::class,
        '<h2>A</h2><h3>B</h3><h3>C</h3><h2>D</h2><h3>E</h3>',
        [],
      ],
      'first heading below h2' => [
        HeadingLevelsCheck::class,
        '<h3>A</h3><h4>B</h4>',
        ['A'],
      ],
      'skipped level' => [
        HeadingLevelsCheck::class,
        '<h2>A</h2><h4>B</h4>',
        ['B'],
      ],
      // Climbing back up the outline is not a skip.
      'climbing back up' => [
        HeadingLevelsCheck::class,
        '<h2>A</h2><h3>B</h3><h4>C</h4><h2>D</h2>',
        [],
      ],
      'h1' => [
        HeadingLevelsCheck::class,
        '<h1>A</h1><h2>B</h2>',
        ['A'],
      ],
      'alt present, empty alt is decorative' => [
        ImageAltCheck::class,
        '<img src="/a.png" alt="A chart"><img src="/b.png" alt="">',
        [],
      ],
      'alt missing' => [
        ImageAltCheck::class,
        '<img src="/a.png">',
        ['/a.png'],
      ],
      'alt is a file name' => [
        ImageAltCheck::class,
        '<img src="/a.png" alt="IMG_0042.jpg">',
        ['/a.png'],
      ],
      'named elements' => [
        EmptyElementsCheck::class,
        '<h2>Text</h2><a href="/a" aria-label="Home"></a><a href="/b"><img src="/b.png" alt="Logo"></a><button><svg><title>Close</title></svg></button><a href="/c" title="Next"></a>',
        [],
      ],
      'empty heading, link and button' => [
        EmptyElementsCheck::class,
        '<h2> </h2><a href="/a"></a><button><svg></svg></button>',
        ['', '', ''],
      ],
      // A link with no href is not a link, and hidden markup is not read.
      'not a link, or hidden' => [
        EmptyElementsCheck::class,
        '<a name="top"></a><div aria-hidden="true"><a href="/a"></a></div>',
        [],
      ],
    ];
  }

}
