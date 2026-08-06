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
 * Components paint their vertical rhythm with padding (`neo-section-y`, applied
 * by the `gap` style prop) so a painted background fills the gap. Padding does
 * not collapse like margin, so two vertically-adjacent sections showing the
 * same colour stack 2x spacing.
 *
 * At build time every (scheme × surface) context is resolved to its rendered
 * background colour by comparing the neo_color token values it would paint
 * with — the same values neo_color emits as CSS variables — and contexts are
 * grouped by colour. Transparent section roots (`neo-section` without
 * `component-bg`) join the page-background group: they paint nothing, so the
 * page colour shows through whatever scheme class they carry. For each group
 * one rule is emitted: when a member is immediately followed by another member
 * of the same group, the *first* section's bottom-spacing channel
 * (`--spacing-component-b`) is zeroed on its root. The vertical carrier
 * utilities resolve through that inherited channel (see the module's
 * `_utilities.css`), so the collapse applies wherever the carrier class sits
 * inside the section — root, direct child, or deeper. The seam left between
 * the two sections is the following section's top padding — a single spacing
 * unit — and because nothing is pulled with a negative margin the sections can
 * never overlap, whatever spacing sizes they use.
 *
 * Sections painting a background outside the known surface vocabulary simply
 * do not collapse. That fails safe: a doubled gap, never an overlap.
 *
 * @see hook_neo_alchemist_component_bg_surfaces_alter()
 * @see hook_neo_alchemist_page_background_alter()
 */
class NeoBuildInlineEventSubscriber implements EventSubscriberInterface {

  /**
   * The background utilities that participate in seam collapsing.
   *
   * Keyed by utility class, value is the neo_color token the utility renders
   * with. `bg-default` resolves through `--background-color-default`, which
   * every scope pins to its own `--color-base-0`. Aliases resolving to the
   * same token (`bg-base-0` / `bg-white` alongside `bg-default`) are
   * deliberately excluded: `bg-default` is the canonical way to paint a
   * scheme-aware section surface.
   */
  private const DEFAULT_SURFACES = [
    'bg-default' => '--color-base-0',
    'bg-base-50' => '--color-base-50',
    'bg-base-100' => '--color-base-100',
    'bg-base-200' => '--color-base-200',
    'bg-base-300' => '--color-base-300',
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

    // The no-scheme token values, mirroring neo_color's :root emission.
    $rootVars = [];
    /** @var \Drupal\neo_color\PalletInterface[] $pallets */
    $pallets = $this->entityTypeManager->getStorage('neo_pallet')->loadByProperties([
      'status' => 1,
    ]);
    foreach ($pallets as $pallet) {
      foreach ($pallet->getCssData() as $key => $value) {
        $rootVars[$key] = $value;
      }
    }

    // Group every (scheme × surface) context by the colour it renders with.
    // Two contexts land in the same group exactly when they would paint the
    // same pixels, however their class tokens differ.
    $groups = [];
    foreach ($surfaces as $surface => $varKey) {
      $color = trim((string) ($rootVars[$varKey] ?? ''));
      if ($color !== '') {
        $groups[$color][] = '.component-bg:not([class*="scheme-"]).' . $surface;
      }
    }
    /** @var \Drupal\neo_color\SchemeInterface[] $schemes */
    $schemes = $this->entityTypeManager->getStorage('neo_scheme')->loadByProperties([
      'status' => 1,
    ]);
    foreach ($schemes as $scheme) {
      // A scheme re-points the tokens it transforms; anything it does not emit
      // falls back to the :root value, exactly as in CSS.
      $schemeVars = array_merge($rootVars, $scheme->getCssData());
      foreach ($surfaces as $surface => $varKey) {
        $color = trim((string) ($schemeVars[$varKey] ?? ''));
        if ($color !== '') {
          $groups[$color][] = '.component-bg.' . $scheme->getSelector() . '.' . $surface;
        }
      }
    }

    // Transparent section roots paint nothing — the page background shows
    // through whatever scheme class they carry — so they join the page
    // background's colour group. The page background defaults to the base
    // surface (base-0); themes painting something else can alter it.
    $pageBg = trim((string) ($rootVars['--color-base-0'] ?? ''));
    $this->moduleHandler->alter('neo_alchemist_page_background', $pageBg);
    if ($pageBg !== '') {
      $groups[$pageBg][] = '.neo-section:not(.component-bg)';
    }

    foreach ($groups as $members) {
      $members = array_map(
        static fn (string $member): string => $member . ':not(.' . self::OPT_OUT_CLASS . ')',
        $members,
      );
      $group = ':is(' . implode(',', $members) . ')';
      $event->addCssValue('--spacing-component-b', '0', $group . ':has(+ ' . $group . ')');
    }

    $event->addCacheTags(['config:neo_scheme_list', 'config:neo_pallet_list']);
  }

  /**
   * Gets the background utilities that participate in seam collapsing.
   *
   * @return array<string, string>
   *   Neo_color token names keyed by background utility class (no leading
   *   dot).
   */
  private function getSurfaces(): array {
    $surfaces = self::DEFAULT_SURFACES;
    $this->moduleHandler->alter('neo_alchemist_component_bg_surfaces', $surfaces);
    return array_filter($surfaces);
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
