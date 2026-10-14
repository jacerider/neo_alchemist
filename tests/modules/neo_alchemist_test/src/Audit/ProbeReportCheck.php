<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_test\Audit;

use Drupal\neo_alchemist\Audit\ComponentAuditCheckInterface;
use Drupal\neo_alchemist\Audit\ComponentAuditContext;

/**
 * A check that reports what it was handed and finds nothing.
 *
 * Registered only by the tests that use it.
 */
class ProbeReportCheck implements ComponentAuditCheckInterface {

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'probe_report';
  }

  /**
   * {@inheritdoc}
   */
  public function check(\DOMDocument $document, ComponentAuditContext $context): array {
    $context->report('Probe', sprintf('%s %s %s', $context->component, $context->variant, $context->entity->getComponentId()));
    return [];
  }

}
