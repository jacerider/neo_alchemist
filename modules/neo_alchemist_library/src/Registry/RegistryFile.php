<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_library\Registry;

/**
 * Value object describing a single file inside a component bundle.
 */
final class RegistryFile {

  /**
   * Write the file into the component folder (renamed to the target name).
   */
  const TARGET_COMPONENT = 'component';

  /**
   * Write the file verbatim into the theme (e.g. a shared partial).
   */
  const TARGET_THEME = 'theme';

  /**
   * Constructs a RegistryFile.
   *
   * @param string $path
   *   The relative path/basename, e.g. "list_s1.twig" or
   *   "includes/list_s1--items.html.twig".
   * @param string $target
   *   Either self::TARGET_COMPONENT or self::TARGET_THEME.
   * @param string $content
   *   The raw file content (base64 encoded when $encoding is "base64").
   * @param string $encoding
   *   Either "utf-8" (default) or "base64" for binary files.
   */
  public function __construct(
    public readonly string $path,
    public readonly string $target,
    public readonly string $content,
    public readonly string $encoding = 'utf-8',
  ) {}

  /**
   * Builds a RegistryFile from a decoded JSON array.
   */
  public static function fromArray(array $data): self {
    if (!isset($data['path'], $data['content'])) {
      throw new RegistryException('Registry file entry is missing "path" or "content".');
    }
    return new self(
      (string) $data['path'],
      ($data['target'] ?? self::TARGET_COMPONENT) === self::TARGET_THEME ? self::TARGET_THEME : self::TARGET_COMPONENT,
      (string) $data['content'],
      ($data['encoding'] ?? 'utf-8') === 'base64' ? 'base64' : 'utf-8',
    );
  }

  /**
   * Returns TRUE when this file belongs in the component folder.
   */
  public function isComponentFile(): bool {
    return $this->target === self::TARGET_COMPONENT;
  }

  /**
   * Returns the decoded binary/text content ready to write to disk.
   */
  public function getDecodedContent(): string {
    if ($this->encoding === 'base64') {
      $decoded = base64_decode($this->content, TRUE);
      if ($decoded === FALSE) {
        throw new RegistryException(sprintf('File "%s" declared base64 encoding but could not be decoded.', $this->path));
      }
      return $decoded;
    }
    return $this->content;
  }

  /**
   * Returns TRUE when the content is text we can safely rewrite (twig/js/yml).
   */
  public function isText(): bool {
    return $this->encoding !== 'base64';
  }

}
