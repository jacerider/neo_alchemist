<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\neo_build\Event\NeoBuildInlineEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Collapses doubled spacing between adjacent same-background components.
 *
 * Background components paint their spacing with padding (e.g. `py-component`)
 * so the background fills the gap. Padding does not collapse like margin, so
 * two vertically-adjacent background sections of the *same* colour stack 2x
 * spacing.
 *
 * For each component flagged with the `component-bg` marker class, when it is
 * immediately followed by another `component-bg` painting the same colour, we
 * drop the *first* section's bottom padding. The seam left between them is the
 * following section's top padding — a single spacing unit — and because nothing
 * is pulled with a negative margin the two sections can never overlap, whatever
 * spacing sizes they use.
 *
 * "Same colour" needs both halves of the surface to match:
 * - the same `scheme-*` class (or neither carrying one, in which case both
 *   inherit the same ancestor/default colour), and
 * - the same background utility (`bg-default`, `bg-base-100`, …), since a
 *   scheme only re-points the colour tokens — it never picks which one a
 *   section paints.
 *
 * Sections painting a background outside the known surface vocabulary simply do
 * not collapse. That fails safe: a doubled gap, never an overlap.
 *
 * @see hook_neo_alchemist_component_bg_surfaces_alter()
 */
class NeoBuildInlineEventSubscriber implements EventSubscriberInterface {

  /**
   * The background utilities that participate in seam collapsing.
   *
   * Aliases resolving to the same token (`bg-base-0` / `bg-white` alongside
   * `bg-default`) are deliberately excluded: `bg-default` is the canonical way
   * to paint a scheme-aware section surface.
   */
  private const DEFAULT_SURFACES = [
    'bg-default',
    'bg-base-50',
    'bg-base-100',
    'bg-base-200',
    'bg-base-300',
    // 'bg-primary',
    // 'bg-secondary',
    // 'bg-accent',
  ];

  /**
   * Marker class opting a section out of collapsing with either neighbour.
   */
  private const OPT_OUT_CLASS = 'component-bg-flush-none';

  /**
   * Constructs a new NeoBuildInlineEventSubscriber object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Injects the same-background spacing-collapse rules.
   *
   * @param \Drupal\neo_build\Event\NeoBuildInlineEvent $event
   *   The neo build inline event.
   */
  public function onInlineBuild(NeoBuildInlineEvent $event) {
    $surfaces = $this->getSurfaces();
    if (!$surfaces) {
      return;
    }

    // No explicit scheme on either section: both inherit the same ancestor /
    // default background colour, so the seam can collapse.
    $schemeSelectors = [':not([class*="scheme-"])'];

    // One entry per scheme: two adjacent background sections carrying the same
    // scheme class resolve their surface token to the same colour.
    /** @var \Drupal\neo_color\SchemeInterface[] $schemes */
    $schemes = $this->entityTypeManager->getStorage('neo_scheme')->loadByProperties([
      'status' => 1,
    ]);
    foreach ($schemes as $scheme) {
      $schemeSelectors[] = '.' . $scheme->getSelector();
    }

    // Emit one rule per scheme, with every surface comma-joined into it.
    foreach ($schemeSelectors as $schemeSelector) {
      $selectors = [];
      foreach ($surfaces as $surface) {
        $section = '.component-bg' . $schemeSelector . '.' . $surface . ':not(.' . self::OPT_OUT_CLASS . ')';
        // The padded wrapper is a direct child of the section root, so `>`
        // keeps this out of any nested region's components. `:where()` keeps
        // the specificity on the section itself.
        $selectors[] = $section . ':has(+ ' . $section . ') > :where(.py-component,.pb-component)';
      }
      $event->addCssValue('padding-bottom', '0', implode(',', $selectors));
    }

    $event->addCacheTags(['config:neo_scheme_list']);
  }

  /**
   * Gets the background utilities that participate in seam collapsing.
   *
   * @return string[]
   *   Background utility class names, without a leading dot.
   */
  private function getSurfaces(): array {
    $surfaces = self::DEFAULT_SURFACES;
    $this->moduleHandler->alter('neo_alchemist_component_bg_surfaces', $surfaces);
    return array_values(array_unique(array_filter($surfaces)));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      NeoBuildInlineEvent::EVENT_NAME => 'onInlineBuild',
    ];
  }

}
