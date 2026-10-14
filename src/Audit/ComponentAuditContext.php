<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Audit;

use Drupal\neo_alchemist\ComponentInterface;

/**
 * What an audit check is checking, besides the markup.
 *
 * The component, as built from its examples (or, in the component preview,
 * the values being edited there), and which render the markup is from. A
 * check that reads the component rather than its markup — a declaration in
 * its definition, say — runs once, on the "examples" render.
 *
 * A check may also add a report: something worth seeing that is not a
 * problem, such as what the component produces. The preview page lists the
 * reports under the issues; one label, one report.
 */
final class ComponentAuditContext {

  /**
   * The reports, by label.
   *
   * @var array<string, array{label: string, content: string}>
   */
  protected array $reports = [];

  /**
   * Constructs the context.
   *
   * @param string $component
   *   The SDC component id.
   * @param string $variant
   *   The render: one of ComponentAudit::VARIANTS.
   * @param \Drupal\neo_alchemist\ComponentInterface $entity
   *   The component, as audited.
   */
  public function __construct(
    public readonly string $component,
    public readonly string $variant,
    public readonly ComponentInterface $entity,
  ) {}

  /**
   * Adds a report, replacing one of the same label.
   *
   * @param string $label
   *   What it is, e.g. "Schema (JSON-LD)".
   * @param string $content
   *   Plain text, shown preformatted.
   */
  public function report(string $label, string $content): void {
    $this->reports[$label] = ['label' => $label, 'content' => $content];
  }

  /**
   * The reports added.
   *
   * @return array<int, array{label: string, content: string}>
   *   In the order they were added.
   */
  public function reports(): array {
    return array_values($this->reports);
  }

}
