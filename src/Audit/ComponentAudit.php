<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Audit;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentPreviewBuilder;

/**
 * Renders a component and runs the audit checks over it.
 *
 * Two renders: with its examples (or, in the component preview, the values
 * being edited there), and without headings: every optional heading prop
 * left out, everything else as before. A component's heading is the content
 * most often left empty, and what is under it has to hold up then: a list
 * whose items are h3s under the component's h2 skips a level the moment that
 * h2 is not there.
 *
 * The checks are services tagged `neo_alchemist.component_audit_check`.
 *
 * @see \Drupal\neo_alchemist\Audit\ComponentAuditCheckInterface
 */
class ComponentAudit {

  /**
   * The renders, by name.
   */
  public const VARIANTS = ['examples', 'without_headings'];

  /**
   * The checks.
   *
   * @var \Drupal\neo_alchemist\Audit\ComponentAuditCheckInterface[]
   */
  protected array $checks;

  /**
   * Constructs the audit.
   *
   * @param \Drupal\neo_alchemist\ComponentPreviewBuilder $previewBuilder
   *   Builds the component from its examples.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param iterable $checks
   *   The tagged checks.
   */
  public function __construct(
    protected ComponentPreviewBuilder $previewBuilder,
    protected RendererInterface $renderer,
    iterable $checks,
  ) {
    $this->checks = [];
    foreach ($checks as $check) {
      if ($check instanceof ComponentAuditCheckInterface) {
        $this->checks[] = $check;
      }
    }
  }

  /**
   * The issues a component has.
   *
   * @param string $component
   *   The SDC component id, e.g. "front:locations".
   * @param \Drupal\Core\Routing\RouteMatchInterface|null $routeMatch
   *   The route to render under, as the preview frame does.
   *
   * @return array<int, array{check: string, variant: string, message: string, locator: array{tag: string, index: int, text: string}|null}>|null
   *   The variant is "examples" or "without_headings".
   *   The issues, an issue found in both renders listed once; NULL when the
   *   component cannot be rendered at all (not a Neo component).
   */
  public function audit(string $component, ?RouteMatchInterface $routeMatch = NULL): ?array {
    return $this->run($component, $routeMatch)['issues'] ?? NULL;
  }

  /**
   * The issues a component has, and what its checks report besides.
   *
   * @param string $component
   *   The SDC component id.
   * @param \Drupal\Core\Routing\RouteMatchInterface|null $routeMatch
   *   The route to render under, as the preview frame does.
   *
   * @return array{issues: array, reports: array<int, array{label: string, content: string}>}|null
   *   The issues, as audit() lists them, and the checks' reports (a label
   *   reported in both renders is the first's). NULL when the component
   *   cannot be rendered at all.
   */
  public function run(string $component, ?RouteMatchInterface $routeMatch = NULL): ?array {
    $issues = [];
    $reports = [];
    $seen = [];
    foreach (static::VARIANTS as $variant) {
      $entity = $this->previewBuilder->build($component);
      if (!$entity) {
        return NULL;
      }
      $context = new ComponentAuditContext($component, $variant, $entity);
      try {
        $html = $this->render($entity, $variant === 'without_headings', $routeMatch);
      }
      catch (\Throwable $e) {
        $issues[] = [
          'check' => 'render',
          'variant' => $variant,
          'message' => sprintf('The component fails to render%s: %s', $variant === 'without_headings' ? ' without its headings' : '', $e->getMessage()),
          'locator' => NULL,
        ];
        continue;
      }
      $document = Html::load($html);
      foreach ($this->checks as $check) {
        // One failing check is an issue of its own, not the end of the audit.
        try {
          $found_issues = $check->check($document, $context);
        }
        catch (\Throwable $e) {
          $found_issues = [['message' => sprintf('The %s check failed: %s', $check->id(), $e->getMessage())]];
        }
        foreach ($found_issues as $found) {
          $locator = isset($found['element']) && $found['element'] instanceof \DOMElement ? $this->locator($found['element']) : NULL;
          $key = $check->id() . '|' . $found['message'] . '|' . json_encode($locator);
          if (isset($seen[$key])) {
            continue;
          }
          $seen[$key] = TRUE;
          $issues[] = [
            'check' => $check->id(),
            'variant' => $variant,
            'message' => (string) $found['message'],
            'locator' => $locator,
          ];
        }
      }
      foreach ($context->reports() as $report) {
        $reports[$report['label']] ??= $report;
      }
    }
    return ['issues' => $issues, 'reports' => array_values($reports)];
  }

  /**
   * Renders the component, with its values or without its headings.
   */
  protected function render(ComponentInterface $entity, bool $withoutHeadings, ?RouteMatchInterface $routeMatch): string {
    $optional = $withoutHeadings ? $this->optionalHeadings($entity) : [];
    return (string) $this->renderer->executeInRenderContext(new RenderContext(), function () use ($entity, $optional, $routeMatch) {
      $build = $entity->toRenderable(routeMatch: $routeMatch);
      foreach ($optional as $name) {
        unset($build['#props'][$name]);
      }
      return $this->renderer->render($build);
    });
  }

  /**
   * The heading props a component may be placed without.
   *
   * @return string[]
   *   Prop names.
   */
  protected function optionalHeadings(ComponentInterface $entity): array {
    $required = (array) ($entity->getComponentDefinition()['props']['required'] ?? []);
    $names = [];
    foreach ($entity->getPropShapes() as $name => $shape) {
      if ($shape->getRef() === 'heading' && !in_array($name, $required, TRUE)) {
        $names[] = (string) $name;
      }
    }
    return $names;
  }

  /**
   * Where an element is: its tag, its place among those tags, its text.
   *
   * Enough for the preview to find it again in the same render.
   */
  protected function locator(\DOMElement $element): array {
    $index = 0;
    foreach ($element->ownerDocument->getElementsByTagName($element->tagName) as $i => $candidate) {
      if ($candidate->isSameNode($element)) {
        $index = $i;
        break;
      }
    }
    $text = trim(preg_replace('/\s+/u', ' ', $element->textContent) ?? '');
    return [
      'tag' => $element->tagName,
      'index' => (int) $index,
      'text' => mb_substr($text, 0, 80),
    ];
  }

}
