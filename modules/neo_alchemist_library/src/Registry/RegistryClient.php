<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_library\Registry;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Fetches and caches the component registry over HTTP (or a local path).
 */
final class RegistryClient {

  /**
   * Cache id prefix.
   */
  const CACHE_PREFIX = 'neo_alchemist_library:registry:';

  /**
   * Cache tag invalidated when the registry cache is cleared.
   */
  const CACHE_TAG = 'neo_alchemist_library:registry';

  /**
   * Index document filename.
   */
  const INDEX_FILE = 'registry.json';

  /**
   * Time-to-live for cached registry documents (seconds).
   */
  const TTL = 3600;

  /**
   * The logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the RegistryClient.
   */
  public function __construct(
    protected readonly ClientInterface $httpClient,
    protected readonly CacheBackendInterface $cache,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('neo_alchemist_library');
  }

  /**
   * Returns the configured registry base URLs (or paths), in priority order.
   *
   * @return string[]
   */
  public function getRegistryUrls(): array {
    $registries = $this->configFactory->get('neo_alchemist_library.settings')->get('registries') ?? [];
    $urls = [];
    foreach ($registries as $registry) {
      $registry = trim((string) $registry);
      if ($registry !== '') {
        $urls[] = rtrim($registry, '/');
      }
    }
    return $urls;
  }

  /**
   * Returns the merged registry index keyed by component machine name.
   *
   * Each entry is the summary array from registry.json plus a "_registry" key
   * holding the base URL it was fetched from.
   *
   * @return array<string, array>
   *
   * @throws \Drupal\neo_alchemist_library\Registry\RegistryException
   *   When no registry could be reached and nothing is cached.
   */
  public function getIndex(bool $refresh = FALSE): array {
    $urls = $this->getRegistryUrls();
    if ($urls === []) {
      throw new RegistryException('No component registry is configured. Set one at /admin/config/neo/alchemist/library/settings.');
    }

    $components = [];
    $errors = [];
    foreach ($urls as $base) {
      try {
        $data = $this->fetchJson($base . '/' . self::INDEX_FILE, 'index:' . $base, $refresh);
      }
      catch (RegistryException $e) {
        $errors[] = $e->getMessage();
        continue;
      }
      foreach ($data['components'] ?? [] as $component) {
        $component = (array) $component;
        if (empty($component['name'])) {
          continue;
        }
        // First registry to define a name wins.
        if (!isset($components[$component['name']])) {
          $component['_registry'] = $base;
          $components[$component['name']] = $component;
        }
      }
    }

    if ($components === [] && $errors !== []) {
      throw new RegistryException('Could not load any component registry: ' . implode(' | ', $errors));
    }

    uasort($components, fn($a, $b) => strnatcasecmp((string) ($a['title'] ?? $a['name']), (string) ($b['title'] ?? $b['name'])));
    return $components;
  }

  /**
   * Returns TRUE when the component exists in the registry index.
   */
  public function has(string $name): bool {
    try {
      return isset($this->getIndex()[$name]);
    }
    catch (RegistryException) {
      return FALSE;
    }
  }

  /**
   * Fetches and decodes a single component bundle (r/<name>.json).
   *
   * @throws \Drupal\neo_alchemist_library\Registry\RegistryException
   *   When the component cannot be found or fetched.
   */
  public function getItem(string $name, bool $refresh = FALSE): RegistryItem {
    // Prefer the registry the index says owns this component.
    $bases = [];
    try {
      $index = $this->getIndex($refresh);
      if (isset($index[$name]['_registry'])) {
        $bases[] = $index[$name]['_registry'];
      }
    }
    catch (RegistryException) {
      // Fall through to trying every configured registry.
    }
    foreach ($this->getRegistryUrls() as $base) {
      if (!in_array($base, $bases, TRUE)) {
        $bases[] = $base;
      }
    }

    $errors = [];
    foreach ($bases as $base) {
      try {
        $data = $this->fetchJson($base . '/r/' . $name . '.json', 'item:' . $base . ':' . $name, $refresh);
        return RegistryItem::fromArray($data);
      }
      catch (RegistryException $e) {
        $errors[] = $e->getMessage();
      }
    }
    throw new RegistryException(sprintf('Component "%s" could not be loaded from the registry: %s', $name, implode(' | ', $errors)));
  }

  /**
   * Clears all cached registry documents.
   */
  public function clearCache(): void {
    \Drupal\Core\Cache\Cache::invalidateTags([self::CACHE_TAG]);
  }

  /**
   * Fetches a URL, decodes JSON, caches it, and falls back to stale on error.
   */
  protected function fetchJson(string $url, string $cacheKey, bool $refresh): array {
    $cid = self::CACHE_PREFIX . $cacheKey;

    if (!$refresh) {
      $cached = $this->cache->get($cid);
      if ($cached && is_array($cached->data)) {
        return $cached->data;
      }
    }

    try {
      $raw = $this->fetchRaw($url);
      $data = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($data)) {
        throw new RegistryException(sprintf('Registry document at %s did not contain a JSON object.', $url));
      }
      $this->cache->set($cid, $data, $this->time->getRequestTime() + self::TTL, [self::CACHE_TAG]);
      return $data;
    }
    catch (\JsonException $e) {
      throw new RegistryException(sprintf('Registry document at %s is not valid JSON: %s', $url, $e->getMessage()));
    }
    catch (RegistryException $e) {
      // Network/IO failure: serve stale cache if we have any.
      $stale = $this->cache->get($cid, TRUE);
      if ($stale && is_array($stale->data)) {
        $this->logger->warning('Registry @url unreachable, serving stale cache. (@msg)', [
          '@url' => $url,
          '@msg' => $e->getMessage(),
        ]);
        return $stale->data;
      }
      throw $e;
    }
  }

  /**
   * Reads the raw body of a URL (http/https) or a local filesystem path.
   *
   * @throws \Drupal\neo_alchemist_library\Registry\RegistryException
   *   On any transport error.
   */
  protected function fetchRaw(string $url): string {
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
      try {
        $response = $this->httpClient->request('GET', $url, [
          'timeout' => 15,
          'headers' => ['Accept' => 'application/json'],
        ]);
        return (string) $response->getBody();
      }
      catch (GuzzleException $e) {
        throw new RegistryException(sprintf('HTTP request to %s failed: %s', $url, $e->getMessage()));
      }
    }

    // Treat anything else as a local path (handy for development/testing).
    $path = str_starts_with($url, 'file://') ? substr($url, 7) : $url;
    if (!is_file($path)) {
      throw new RegistryException(sprintf('Local registry file not found: %s', $path));
    }
    $contents = @file_get_contents($path);
    if ($contents === FALSE) {
      throw new RegistryException(sprintf('Unable to read local registry file: %s', $path));
    }
    return $contents;
  }

}
