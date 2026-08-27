<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_search\Binding;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Value\ComponentValueFieldSourceInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginManagerInterface;

/**
 * Learns which entity fields a component surfaces, by asking its value plugins.
 *
 * A layout shared by every entity of a bundle still shows entity-specific text,
 * because its props are fed by value plugins bound to the host entity's fields.
 * Those bindings are configuration, so the fields can be read directly instead
 * of rendering the page to find out what it said.
 *
 * Each plugin is asked what it reads rather than being interpreted here: the
 * settings key that means "read this field" in one plugin means something else
 * in the next, and a plugin from another package can be added at any time. The
 * question is put to the plugin *class* rather than an instance, because
 * constructing a value plugin needs a shape, which needs a loaded component and
 * a host entity — costs this runs over every entity on the site and cannot
 * afford.
 *
 * What stays here is the consumer's own policy: how far a reference may be
 * followed, which pseudo-fields are not worth reading, and what to cache. Those
 * are decisions about indexing, not facts about a plugin.
 *
 * Memoised per component rather than per bundle. A component's bindings are a
 * property of the component alone, several bundles reuse the same components,
 * and an entity's own tree may place components the bundle default layout never
 * mentions.
 *
 * @see \Drupal\neo_alchemist\Value\ComponentValueFieldSourceInterface
 */
final class BindingResolver {

  /**
   * How many reference hops a binding may cross.
   *
   * One hop admits the fields that describe the entity — a job posting's
   * location term, its job type — while refusing chains that wander off into
   * unrelated content. More than one reintroduces the cross-entity sprawl that
   * made indexing rendered output unusable.
   */
  private const MAX_HOPS = 1;

  /**
   * Pseudo-fields in the matcher grammar that never carry indexable text.
   */
  private const PSEUDO_FIELDS = [
    '_default' => TRUE,
    '_nothing' => TRUE,
    '_none' => TRUE,
  ];

  /**
   * Resolved sets, keyed by component id, for the life of the request.
   *
   * @var array<string, \Drupal\neo_alchemist_search\Binding\BindingSet>
   */
  private array $memo = [];

  /**
   * Constructs a BindingResolver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads component config entities.
   * @param \Drupal\neo_alchemist\Value\ComponentValuePluginManagerInterface $valuePluginManager
   *   Provides plugin definitions; no plugin is ever instantiated.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   Persists resolved sets between requests.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ComponentValuePluginManagerInterface $valuePluginManager,
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * Forgets everything resolved so far in this request.
   *
   * The persistent cache is invalidated by config tags, but the in-request memo
   * has no way to hear about that — and a component edit followed by indexing
   * in the same request is exactly what happens when an index updates
   * immediately.
   */
  public function reset(): void {
    $this->memo = [];
  }

  /**
   * Resolves the bindings a component declares.
   *
   * @param string $componentId
   *   A neo_component config entity id.
   *
   * @return \Drupal\neo_alchemist_search\Binding\BindingSet
   *   The fields it surfaces, and which plugins declared none.
   */
  public function resolve(string $componentId): BindingSet {
    if (isset($this->memo[$componentId])) {
      return $this->memo[$componentId];
    }
    $cid = 'neo_alchemist_search:bindings:' . $componentId;
    $cached = $this->cache->get($cid);
    if ($cached && $cached->data instanceof BindingSet) {
      return $this->memo[$componentId] = $cached->data;
    }

    $set = $this->build($componentId);
    // Tagged with the component alone: editing it is the only thing that can
    // change what it declares.
    $this->cache->set($cid, $set, CacheBackendInterface::CACHE_PERMANENT, [
      'config:neo_alchemist.neo_component.' . $componentId,
    ]);
    return $this->memo[$componentId] = $set;
  }

  /**
   * Builds the binding set for a component from its stored settings.
   */
  private function build(string $componentId): BindingSet {
    $component = $this->entityTypeManager->getStorage('neo_component')->load($componentId);
    if (!$component instanceof ComponentInterface) {
      return new BindingSet();
    }

    $descriptors = [];
    $silent = [];

    foreach (($component->get('settings')['props'] ?? []) as $prop) {
      if (!is_array($prop)) {
        continue;
      }
      // An inactive prop is not rendered, so nothing it names is on the page.
      if (array_key_exists('active', $prop) && !$prop['active']) {
        continue;
      }
      $plugins = $prop['plugins'] ?? [];
      if (!is_array($plugins)) {
        continue;
      }
      foreach ($plugins as $shapeId => $byPlugin) {
        if (!is_array($byPlugin)) {
          continue;
        }
        foreach ($byPlugin as $pluginId => $config) {
          $pluginId = (string) $pluginId;
          $settings = is_array($config) ? ($config['settings'] ?? []) : [];
          $settings = is_array($settings) ? $settings : [];

          $class = $this->sourceClass($pluginId);
          if ($class === NULL) {
            // Not a field source: it produces the same thing on every entity,
            // reads some other entity, or is not text at all. That is the
            // plugin's own declaration, made by not implementing the interface.
            $silent[$pluginId] = ($silent[$pluginId] ?? 0) + 1;
            continue;
          }
          foreach ($class::getSourceFieldKeys($settings) as $key) {
            $this->addDescriptor($descriptors, $key, $componentId, (string) $shapeId, $pluginId);
          }
        }
      }
    }

    return new BindingSet(array_values($descriptors), $silent);
  }

  /**
   * The plugin class for an id, when it declares itself a field source.
   *
   * @param string $pluginId
   *   The value plugin id.
   *
   * @return class-string<\Drupal\neo_alchemist\Value\ComponentValueFieldSourceInterface>|null
   *   The class, or NULL when the plugin is unknown or reads no host field.
   */
  private function sourceClass(string $pluginId): ?string {
    $definition = $this->valuePluginManager->getDefinition($pluginId, FALSE);
    $class = is_array($definition) ? ($definition['class'] ?? NULL) : NULL;
    if (!is_string($class) || !is_a($class, ComponentValueFieldSourceInterface::class, TRUE)) {
      return NULL;
    }
    return $class;
  }

  /**
   * Records one field key, if it is one worth reading.
   *
   * @param \Drupal\neo_alchemist_search\Binding\BindingDescriptor[] $descriptors
   *   Collected descriptors, keyed by dedupe key.
   * @param mixed $key
   *   The field key the plugin declared.
   * @param string $componentId
   *   The declaring component.
   * @param string $shapeId
   *   The shape being fed.
   * @param string $pluginId
   *   The declaring plugin.
   */
  private function addDescriptor(array &$descriptors, mixed $key, string $componentId, string $shapeId, string $pluginId): void {
    if (!is_string($key)) {
      return;
    }
    $key = trim($key);
    if ($key === '') {
      return;
    }
    $hops = substr_count($key, '.');
    if ($hops > self::MAX_HOPS) {
      return;
    }
    foreach (explode('.', $key) as $hop) {
      $name = explode(':', $hop)[0];
      // A pseudo-field, or a hop naming the entity's own link or another
      // structural value rather than a field.
      if ($name === '' || isset(self::PSEUDO_FIELDS[$name]) || str_starts_with($name, '_')) {
        return;
      }
    }
    $descriptor = new BindingDescriptor($key, $hops, $componentId, $shapeId, $pluginId);
    $descriptors[$descriptor->dedupeKey()] = $descriptor;
  }

}
