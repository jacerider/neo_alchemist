<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Audit;

/**
 * One check the component audit runs over a rendered component.
 *
 * A service tagged `neo_alchemist.component_audit_check`. The audit renders a
 * component (with its examples, and without its optional headings) and hands
 * each check the HTML as a document whose body holds the component and
 * nothing else, with the context: the component itself and which render it
 * is. A module adds checks of its own the same way.
 *
 * @see \Drupal\neo_alchemist\Audit\ComponentAudit
 */
interface ComponentAuditCheckInterface {

  /**
   * A short machine name for the check, e.g. "heading_levels".
   */
  public function id(): string;

  /**
   * The issues the check finds.
   *
   * @param \DOMDocument $document
   *   The rendered component.
   * @param \Drupal\neo_alchemist\Audit\ComponentAuditContext $context
   *   The component and the render; a check may add a report to it.
   *
   * @return array<int, array{message: string, element?: \DOMElement|null}>
   *   Each issue: what is wrong, and the element it is about where there is
   *   one (the audit turns it into a locator the preview can find).
   */
  public function check(\DOMDocument $document, ComponentAuditContext $context): array;

}
