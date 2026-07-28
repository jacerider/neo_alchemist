<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\neo_build\Event\NeoBuildInlineEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Collapses doubled spacing between adjacent same-background components.
 *
 * Background components paint their spacing with padding (e.g. `py-component`)
 * so the background fills the gap. Padding does not collapse like margin, so two
 * vertically-adjacent background sections of the *same* colour stack 2x spacing.
 *
 * For each component flagged with the `component-bg` marker class, when it
 * immediately follows another `component-bg` of the same colour scheme, we pull
 * it up by one spacing unit. The two same-colour backgrounds overlap seamlessly
 * and the visible gap collapses from 2x back to 1x.
 *
 * "Same colour" is matched by scheme-class equality: components share any
 * ancestor scheme, so their resolved background colour matches when both carry
 * the same `scheme-*` class, or when neither carries one (both inherit the
 * ancestor/default colour).
 */
class NeoBuildInlineEventSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a new NeoBuildInlineEventSubscriber object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Injects the same-background spacing-collapse rules.
   *
   * @param \Drupal\neo_build\Event\NeoBuildInlineEvent $event
   *   The neo build inline event.
   */
  public function onInlineBuild(NeoBuildInlineEvent $event) {
    // Cancel one of the two stacked paddings. Falls back to 0 (no-op) when a
    // section has no --spacing-component set.
    $collapse = 'calc(-1 * var(--spacing-component, 0px))';

    // No explicit scheme on either section: both inherit the same ancestor /
    // default background colour, so collapse the seam.
    $event->addCssValue(
      'margin-top',
      $collapse,
      '.component-bg:not([class*="scheme-"]) + .component-bg:not([class*="scheme-"])',
    );

    // One rule per scheme: two adjacent background sections that carry the same
    // scheme class paint the same colour.
    /** @var \Drupal\neo_color\SchemeInterface[] $schemes */
    $schemes = $this->entityTypeManager->getStorage('neo_scheme')->loadByProperties([
      'status' => 1,
    ]);
    foreach ($schemes as $scheme) {
      $selector = $scheme->getSelector();
      $event->addCssValue(
        'margin-top',
        $collapse,
        ".component-bg.$selector + .component-bg.$selector",
      );
    }
    $event->addCacheTags(['config:neo_scheme_list']);
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
