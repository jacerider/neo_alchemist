<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Audit\Check;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_alchemist\Audit\ComponentAuditCheckInterface;
use Drupal\neo_alchemist\Audit\ComponentAuditContext;

/**
 * Headings that break the page outline.
 *
 * A component is placed under the page's H1, so its first heading may be at
 * most an h2, and no heading may skip a level after the one before it (an h4
 * straight after an h2). An h1 is the page's alone: a component's heading
 * that can be the H1 prints heading.tag, which renders h2 outside a page.
 */
class HeadingLevelsCheck implements ComponentAuditCheckInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'heading_levels';
  }

  /**
   * {@inheritdoc}
   */
  public function check(\DOMDocument $document, ComponentAuditContext $context): array {
    $issues = [];
    $previous = 1;
    $first = TRUE;
    foreach ((new \DOMXPath($document))->query('//h1|//h2|//h3|//h4|//h5|//h6') as $heading) {
      /** @var \DOMElement $heading */
      $level = (int) substr($heading->tagName, 1);
      if ($level === 1) {
        $issues[] = [
          'message' => (string) $this->t('An h1 in a component: a page has one H1. Print the heading prop’s tag (heading.tag) instead.'),
          'element' => $heading,
        ];
      }
      elseif ($level > $previous + 1) {
        $issues[] = [
          'message' => $first
            ? (string) $this->t('The first heading is an h@level; under the page’s H1 it should be an h2.', ['@level' => $level])
            : (string) $this->t('Heading skips a level: an h@level after an h@previous.', [
              '@level' => $level,
              '@previous' => $previous,
            ]),
          'element' => $heading,
        ];
      }
      $previous = $level;
      $first = FALSE;
    }
    return $issues;
  }

}
