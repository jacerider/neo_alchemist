<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_alchemist_test\Audit\ProbeReportCheck;
use Drupal\neo_alchemist_test\Lint\ProbeLinter;
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
   * {@inheritdoc}
   *
   * A check that reports, and a linter, from another module's point of view.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('neo_alchemist_test.probe_report_check', ProbeReportCheck::class)
      ->addTag('neo_alchemist.component_audit_check');
    $container->register('neo_alchemist_test.probe_linter', ProbeLinter::class)
      ->addTag('neo_alchemist.component_linter');
  }

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
   * A check sees the component and the render, and may report.
   *
   * One report per label: the examples render's, as the first.
   */
  public function testChecksReport(): void {
    $result = $this->container->get('neo_alchemist.component_audit')->run('neo_alchemist_test:na_heading_tag');
    $this->assertSame([], $result['issues']);
    $this->assertSame([
      ['label' => 'Probe', 'content' => 'neo_alchemist_test:na_heading_tag examples neo_alchemist_test:na_heading_tag'],
    ], $result['reports']);
  }

  /**
   * Another module's linter adds its errors and warnings.
   */
  public function testLinters(): void {
    $linters = $this->container->get('neo_alchemist.component_linters');
    $this->assertSame(
      [['neo_alchemist_test:na_leaf declares no probe.'], ['A warning.']],
      $linters->lint('neo_alchemist_test:na_leaf', []),
    );
    $this->assertSame([[], []], $linters->lint('neo_alchemist_test:na_leaf', ['probe' => TRUE]));
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
