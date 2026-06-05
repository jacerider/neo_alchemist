<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_library\Registry;

/**
 * Value object for one component bundle (the decoded r/<name>.json).
 */
final class RegistryItem {

  /**
   * Constructs a RegistryItem.
   *
   * @param string $name
   *   The component machine name as authored in the registry.
   * @param string $title
   *   Human readable title.
   * @param string $description
   *   One line description.
   * @param string $status
   *   stable|beta|experimental|deprecated.
   * @param string[] $libraries
   *   Drupal library dependencies declared by the component (informational).
   * @param string[] $registryDependencies
   *   Other registry component names that must also be installed.
   * @param \Drupal\neo_alchemist_library\Registry\RegistryFile[] $files
   *   The files that make up this component.
   * @param string|null $thumbnail
   *   Absolute URL to a preview image, if any.
   */
  public function __construct(
    public readonly string $name,
    public readonly string $title,
    public readonly string $description,
    public readonly string $status,
    public readonly array $libraries,
    public readonly array $registryDependencies,
    public readonly array $files,
    public readonly ?string $thumbnail = NULL,
  ) {}

  /**
   * Builds a RegistryItem from a decoded JSON array.
   */
  public static function fromArray(array $data): self {
    if (empty($data['name'])) {
      throw new RegistryException('Registry item is missing a "name".');
    }
    $files = [];
    foreach ($data['files'] ?? [] as $file) {
      $files[] = RegistryFile::fromArray((array) $file);
    }
    return new self(
      (string) $data['name'],
      (string) ($data['title'] ?? $data['name']),
      (string) ($data['description'] ?? ''),
      (string) ($data['status'] ?? 'stable'),
      array_values(array_map('strval', $data['libraries'] ?? [])),
      array_values(array_map('strval', $data['registryDependencies'] ?? [])),
      $files,
      isset($data['thumbnail']) ? (string) $data['thumbnail'] : NULL,
    );
  }

}
