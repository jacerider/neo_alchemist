<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist;

/**
 * What a heading's tag depends on that can be read from source alone.
 *
 * A heading prop hands Twig `heading.tag`: `h1` for the one heading that is
 * the page's H1, otherwise the prop's fallback. These are the parts of that
 * decision that need no tree — the fallback a prop declares, and whether a
 * component's template prints the tag at all — kept free of services so they
 * can be pinned without a kernel.
 *
 * @see \Drupal\neo_alchemist\PageHeading
 */
final class HeadingTag {

  /**
   * The tag a heading renders when it is not the page's H1.
   */
  public const FALLBACK = 'h2';

  /**
   * The tag a heading prop renders when it is not the page's H1.
   *
   * A prop may declare `tag: h3` (a card title, say). `h1` is not a fallback:
   * the page's H1 is decided per page, and a prop that always printed one
   * would give every page using it a second.
   *
   * @param array $schema
   *   The heading prop's schema.
   *
   * @return string
   *   One of h2–h6.
   */
  public static function fallback(array $schema): string {
    $tag = strtolower((string) ($schema['tag'] ?? ''));
    return preg_match('/^h[2-6]$/', $tag) ? $tag : self::FALLBACK;
  }

  /**
   * Whether a heading prop may be the page's H1 at all.
   *
   * A prop opts out with `h1: false` — an alert banner, a call to action —
   * and the next heading on the page is considered instead.
   *
   * @param array $schema
   *   The heading prop's schema.
   */
  public static function allowsH1(array $schema): bool {
    return ($schema['h1'] ?? TRUE) !== FALSE;
  }

  /**
   * Whether a template prints a heading's tag.
   *
   * Only a template that prints `<name>.tag` can be handed the H1: one that
   * types its own `<h2>` would print an h2 regardless, and the page would end
   * up with no H1 once the hidden page title made way for it. The match is on
   * the heading's own key, so a heading inside an array item — printed as
   * `slide.heading.tag` in a loop — is found too.
   *
   * @param string $twig
   *   The template source.
   * @param string $name
   *   The heading prop's key.
   */
  public static function printsTag(string $twig, string $name): bool {
    $name = preg_quote($name, '/');
    return (bool) preg_match('/\b' . $name . '\s*(?:\.\s*tag\b|\[\s*[\'"]tag[\'"]\s*\])/', self::stripComments($twig));
  }

  /**
   * Whether a template types a literal `<h1>`.
   *
   * @param string $twig
   *   The template source.
   */
  public static function printsLiteralH1(string $twig): bool {
    return (bool) preg_match('/<h1[\s>\/]/i', self::stripComments($twig));
  }

  /**
   * The source without Twig comments, which never print.
   */
  private static function stripComments(string $twig): string {
    return (string) preg_replace('/\{#.*?#\}/s', '', $twig);
  }

}
