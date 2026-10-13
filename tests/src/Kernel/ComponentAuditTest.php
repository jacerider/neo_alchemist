<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A component rendered from its examples, and without its headings, audited.
 *
 * @see \Drupal\neo_alchemist\Audit\ComponentAudit
 */
#[Group('neo_alchemist')]
class ComponentAuditTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'neo_icon',
    'neo_tooltip',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * The audit.
   */
  private function audit(string $component): ?array {
    return $this->container->get('neo_alchemist.component_audit')->audit($component);
  }

  /**
   * Every check runs, each issue is listed once, with where it was found.
   *
   * The h3 items sit under the component's h2 in its examples, and skip a
   * level only once the optional heading is left out; the image and the link
   * are at fault in both renders and listed for the first.
   */
  public function testIssues(): void {
    $issues = $this->audit('neo_alchemist_test:na_audit_probe');
    $this->assertSame([
      ['image_alt', 'examples', 'img', 0],
      ['empty_elements', 'examples', 'a', 0],
      ['heading_levels', 'without_headings', 'h3', 0],
    ], array_map(fn(array $issue) => [
      $issue['check'],
      $issue['variant'],
      $issue['locator']['tag'],
      $issue['locator']['index'],
    ], $issues));
    $this->assertSame('Item', $issues[2]['locator']['text']);
  }

  /**
   * A component with nothing to flag has no issues.
   */
  public function testClean(): void {
    $this->assertSame([], $this->audit('neo_alchemist_test:na_heading_tag'));
  }

  /**
   * Only Neo components are audited.
   */
  public function testNotNeo(): void {
    $this->assertNull($this->audit('neo_alchemist_test:na_not_neo'));
    $this->assertNull($this->audit('neo_alchemist_test:does_not_exist'));
  }

}
