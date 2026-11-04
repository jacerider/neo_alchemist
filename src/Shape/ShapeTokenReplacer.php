<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Shape;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\Markup;
use Drupal\Core\Utility\Token;
use Drupal\neo_alchemist\MissingHostEntityException;
use Drupal\token\TokenEntityMapperInterface;

/**
 * Replaces tokens in the free-text values a shape renders.
 *
 * Every string, markup and uri value goes through here on its way to Twig, so
 * an editor can write `?room=[node:nid]` into a button's link or "Reserve
 * [node:title]" into a heading with no value plugin attached. Because it runs
 * on every site's saved content, it touches only tokens of a REGISTERED type:
 * core's scanner reads any `[word:anything]` as a token and its `clear` option
 * removes them all, which would silently eat an editor's "[Note: call ahead]".
 * A registered token that resolves to nothing is removed.
 *
 * Tokens resolve against the shape's context entity (the host, or the row a
 * producer filled) plus the global types. A placeholder host (an unsaved
 * stand-in for a component with no real host) is not data: its tokens resolve
 * to nothing rather than to a throwaway entity's empty fields.
 *
 * Escaping follows where the value lands:
 * - plain: replacePlain(), since Twig escapes on output.
 * - markup: replace(), so a token value is HTML-escaped inside trusted HTML.
 * - uri: plain, and a token in the query or fragment is URL-encoded, so a
 *   title holding `&` or `#` cannot split the query. A token in the path is
 *   left raw: it may be a path itself.
 *
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginBase::getTokenContext()
 */
final class ShapeTokenReplacer {

  /**
   * Plain text that Twig escapes.
   */
  public const PLAIN = 'plain';

  /**
   * Trusted HTML.
   */
  public const MARKUP = 'markup';

  /**
   * A uri.
   */
  public const URI = 'uri';

  /**
   * The registered token types, keyed by type.
   *
   * @var array<string, true>|null
   */
  private ?array $types = NULL;

  public function __construct(
    private readonly Token $token,
    private readonly ?TokenEntityMapperInterface $entityMapper = NULL,
  ) {}

  /**
   * Replaces the registered tokens in a value.
   *
   * @param string|\Drupal\Component\Render\MarkupInterface $value
   *   The value. Markup is always treated as markup, whatever the context.
   * @param string $context
   *   One of the PLAIN, MARKUP or URI constants.
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The shape the value belongs to. It supplies the entity and receives the
   *   cacheability.
   *
   * @return string|\Drupal\Component\Render\MarkupInterface
   *   The value with its registered tokens replaced, of the same type. The
   *   value itself when it holds none.
   */
  public function replace(string|MarkupInterface $value, string $context, ComponentShapePluginInterface $shape): string|MarkupInterface {
    $text = (string) $value;
    if (!str_contains($text, '[')) {
      return $value;
    }
    $tokens = $this->registeredTokens($text);
    if (!$tokens) {
      return $value;
    }

    $metadata = new BubbleableMetadata();
    $data = $this->data($shape, $metadata);
    $markup = $value instanceof MarkupInterface || $context === self::MARKUP;
    $encode = $context === self::URI && !$markup ? $this->queryTokens($text, $tokens) : [];
    $options = [
      // Clearing is done by the callback, for registered tokens only.
      'clear' => FALSE,
      'callback' => static function (array &$replacements) use ($tokens, $encode): void {
        $replacements = array_intersect_key($replacements, $tokens) + array_fill_keys(array_keys($tokens), '');
        foreach ($encode as $original) {
          $replacements[$original] = rawurlencode((string) $replacements[$original]);
        }
      },
    ];
    $replaced = $markup
      ? $this->token->replace($text, $data, $options, $metadata)
      : $this->token->replacePlain($text, $data, $options, $metadata);
    $shape->addCacheableDependency($metadata);

    return $value instanceof MarkupInterface ? Markup::create($replaced) : $replaced;
  }

  /**
   * The registered tokens in a text.
   *
   * @param string $text
   *   The text.
   *
   * @return array<string, string>
   *   Each registered token as it appears in the text, keyed by itself.
   */
  private function registeredTokens(string $text): array {
    $this->types ??= array_fill_keys(array_keys($this->token->getInfo()['types'] ?? []), TRUE);
    $tokens = [];
    foreach (array_intersect_key($this->token->scan($text), $this->types) as $originals) {
      foreach ($originals as $original) {
        $tokens[$original] = $original;
      }
    }
    return $tokens;
  }

  /**
   * The tokens that sit in a uri's query or fragment.
   *
   * @param string $uri
   *   The uri.
   * @param array<string, string> $tokens
   *   The tokens in it.
   *
   * @return string[]
   *   The tokens first found after the `?` or `#` that ends the path.
   */
  private function queryTokens(string $uri, array $tokens): array {
    $path = strcspn($uri, '?#');
    return array_filter($tokens, fn (string $token) => strpos($uri, $token) >= $path);
  }

  /**
   * The token data for a shape: its context entity, when it has a real one.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface $shape
   *   The shape.
   * @param \Drupal\Core\Render\BubbleableMetadata $metadata
   *   Receives the entity as a dependency.
   *
   * @return array
   *   The data, keyed by token type. Empty when there is no host to read.
   */
  private function data(ComponentShapePluginInterface $shape, BubbleableMetadata $metadata): array {
    try {
      $entity = $shape->getContextEntity();
    }
    catch (MissingHostEntityException) {
      return [];
    }
    if ($entity->isNew()) {
      return [];
    }
    $entityTypeId = $entity->getEntityTypeId();
    // Without the token module, the well-known exceptions to "the token type
    // is the entity type id", as ComponentValueTokenTrait does.
    $type = match (TRUE) {
      isset($this->entityMapper) => $this->entityMapper->getTokenTypeForEntityType($entityTypeId, TRUE),
      $entityTypeId === 'taxonomy_term' => 'term',
      $entityTypeId === 'taxonomy_vocabulary' => 'vocabulary',
      default => $entityTypeId,
    };
    $metadata->addCacheableDependency($entity);
    return [$type => $entity];
  }

}
