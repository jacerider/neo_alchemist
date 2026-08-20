<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Slot;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Render\ElementInfoManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Theme\Registry;
use Drupal\neo_alchemist\ComponentInterface;

/**
 * Defines a component slot.
 */
class ComponentSlot implements ComponentSlotInterface {

  use DependencySerializationTrait;

  /**
   * Context variable names a plugin key may never take.
   *
   * The first group is what ::toRenderable() puts in the slot template's
   * context itself. The rest are Twig literals and keywords: `{{ true }}`
   * prints the literal `1` and `{{ loop }}` collides inside a `{% for %}`,
   * neither of which raises an error — a silent wrong render is worse than a
   * parse failure, so these are taken out of circulation up front.
   */
  private const RESERVED_KEYS = [
    'items', 'slot', 'neoIsPreview', '_neo_slot_template',
    'true', 'false', 'null', 'none', 'and', 'or', 'not', 'in', 'is',
    'if', 'else', 'elseif', 'for', 'loop', 'do', 'with',
    '_context', '_charset', '_self', 'attributes',
  ];

  /**
   * The pattern a configured key must match to be usable as a Twig variable.
   */
  public const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

  /**
   * The slot manager.
   *
   * @var \Drupal\neo_alchemist\Slot\ComponentSlotPluginManager
   */
  protected $manager;

  /**
   * The component.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $component;

  /**
   * The slot name.
   *
   * @var string
   */
  protected $name;

  /**
   * The slot schema.
   *
   * @var array
   */
  protected $schema;

  /**
   * The slot settings.
   *
   * @var array
   */
  protected $settings;

  /**
   * The slot plugins.
   *
   * @var \Drupal\neo_alchemist\Slot\ComponentSlotPluginInterface[]
   */
  protected $plugins;

  /**
   * The resolved Twig keys, keyed by plugin UUID.
   *
   * @var string[]
   */
  protected $keys;

  /**
   * The slot template locator.
   *
   * @var \Drupal\neo_alchemist\Slot\ComponentSlotTemplateLocator|null
   */
  protected ?ComponentSlotTemplateLocator $templateLocator;

  /**
   * The element info manager.
   *
   * @var \Drupal\Core\Render\ElementInfoManagerInterface|null
   */
  protected ?ElementInfoManagerInterface $elementInfo;

  /**
   * The theme registry.
   *
   * Only read by ::getItemInfo(), never on the render path.
   *
   * @var \Drupal\Core\Theme\Registry|null
   */
  protected ?Registry $themeRegistry;

  /**
   * Constructs a new ComponentSlot object.
   */
  public function __construct(ComponentSlotPluginManager $manager, ComponentInterface $component, string $name, array $schema, array $settings, ?ComponentSlotTemplateLocator $templateLocator = NULL, ?ElementInfoManagerInterface $elementInfo = NULL, ?Registry $themeRegistry = NULL) {
    $this->manager = $manager;
    $this->component = $component;
    $this->name = $name;
    $this->schema = $schema;
    $this->settings = $settings;
    $this->templateLocator = $templateLocator;
    $this->elementInfo = $elementInfo;
    $this->themeRegistry = $themeRegistry;
  }

  /**
   * {@inheritDoc}
   */
  public function getComponent(): ComponentInterface {
    return $this->component;
  }

  /**
   * {@inheritDoc}
   */
  public function getName(): string {
    return $this->name;
  }

  /**
   * {@inheritDoc}
   */
  public function getSchema(): array {
    return $this->schema;
  }

  /**
   * {@inheritDoc}
   */
  public function getTitle(): string {
    return $this->schema['title'] ?? 'Unnamed Slot';
  }

  /**
   * {@inheritDoc}
   */
  public function getDescription(): string {
    return $this->schema['description'] ?? 'Unnamed Slot';
  }

  /**
   * {@inheritDoc}
   */
  public function getSettings(): array {
    return $this->settings;
  }

  /**
   * {@inheritDoc}
   */
  public function getPlugins(): array {
    if (!isset($this->plugins)) {
      $this->plugins = [];
      foreach ($this->settings['plugins'] ?? [] as $uuid => $data) {
        if ($this->manager->hasDefinition($data['plugin'])) {
          $this->plugins[$uuid] = $this->manager->createInstance($data['plugin'], [
            'component' => $this->component,
            'uuid' => $uuid,
            'settings' => $data['settings'] ?? [],
          ]);
        }
      }
    }
    return $this->plugins;
  }

  /**
   * {@inheritDoc}
   */
  public function getPlugin(string $uuid): ?ComponentSlotPluginInterface {
    return $this->getPlugins()[$uuid] ?? NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function getKeys(): array {
    if (!isset($this->keys)) {
      $this->keys = [];
      $used = array_fill_keys(self::RESERVED_KEYS, TRUE);
      // Iterate the instantiated plugins rather than the raw settings:
      // ::getPlugins() drops any whose definition has gone away, and a plugin
      // that no longer exists must not still reserve `views_pager` and push the
      // live one to `views_pager_2`.
      foreach ($this->getPlugins() as $uuid => $plugin) {
        $configured = $this->settings['plugins'][$uuid]['key'] ?? '';
        // Treat an unusable stored value as absent. Config reaches us from
        // `drush cim` and hand edits as well as from the form, and a key that
        // is not a legal Twig identifier would be a runtime SyntaxError — a
        // white screen — rather than anything catchable.
        $base = (is_string($configured) && preg_match(self::KEY_PATTERN, $configured))
          ? $configured
          : $plugin->getPluginId();
        $key = $base;
        for ($i = 2; isset($used[$key]); $i++) {
          $key = $base . '_' . $i;
        }
        $used[$key] = TRUE;
        $this->keys[$uuid] = $key;
      }
    }
    return $this->keys;
  }

  /**
   * {@inheritDoc}
   */
  public function getKey(string $uuid): ?string {
    $configured = $this->settings['plugins'][$uuid]['key'] ?? '';
    return (is_string($configured) && $configured !== '') ? $configured : NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function setKey(string $uuid, ?string $key): self {
    if (!isset($this->getPlugins()[$uuid])) {
      return $this;
    }
    if ($key === NULL || $key === '') {
      unset($this->settings['plugins'][$uuid]['key']);
    }
    else {
      $this->settings['plugins'][$uuid]['key'] = $key;
    }
    unset($this->keys);
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function addPlugin(string $plugin_id, $settings = []): ComponentSlotPluginInterface {
    $plugins = $this->getPlugins();
    $plugin = $this->manager->createInstance($plugin_id, [
      'component' => $this->component,
      'uuid' => \Drupal::service('uuid')->generate(),
      'settings' => $settings,
    ]);
    $this->plugins = $plugins + [
      $plugin->uuid() => $plugin,
    ];
    unset($this->keys);
    return $plugin;
  }

  /**
   * {@inheritDoc}
   */
  public function removePlugin(string $uuid): self {
    unset($this->plugins[$uuid]);
    unset($this->keys);
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function toArray(): array {
    $settings = $this->getSettings();
    $keys = $this->getKeys();
    $settings['plugins'] = [];
    foreach ($this->getPlugins() as $uuid => $plugin) {
      $item = [
        'plugin' => $plugin->getPluginId(),
        'settings' => $plugin->getConfiguration(),
      ];
      // Persist the key only when it is not simply the plugin id — either an
      // explicit override or an auto-resolved collision suffix. Freezing a
      // resolved suffix here is what stops two same-plugin items swapping their
      // Twig keys when a site builder drags them past each other; and skipping
      // the default case keeps existing exported config byte-identical until
      // somebody actually sets a key.
      if ($keys[$uuid] !== $plugin->getPluginId()) {
        $item['key'] = $keys[$uuid];
      }
      $settings['plugins'][$uuid] = $item;
    }
    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    $keys = $this->getKeys();
    $build = [];
    foreach ($this->getPlugins() as $uuid => $plugin) {
      $child = $plugin->toRenderable();
      if (is_array($child) && $child) {
        $child = $this->prepareChild($child, $keys[$uuid]);
      }
      $build[$keys[$uuid]] = $child;
    }
    $build = array_filter($build);

    // Return empty rather than an (always truthy) wrapper. Component::
    // toRenderable() filters empty slots out of '#slots' so that core generates
    // no `{% block %}` override for them, which is precisely what lets a
    // component keep its own fallback content inside
    // `{% block name %}…{% endblock %}`. Wrapping unconditionally would make
    // every declared slot look filled and suppress every fallback on the site.
    if (!$build) {
      return [];
    }

    $template = $this->templateLocator?->getTemplate($this->component->getComponentId(), $this->name);
    if (!$template) {
      // Still announce the slot in development, so view-source says which file
      // to create. Nothing else does: a slot layout template is pulled in by
      // {% include %}, never through a theme hook, so core's Twig debug never
      // mentions it whether it exists or not.
      return $this->annotateSlot($build);
    }

    return $this->annotateSlot([
      // The '#template' string is a constant on purpose. TwigEnvironment::
      // renderInline() keys its compiled-template cache on the string itself,
      // so passing the path through '#context' means every slot on the site
      // shares one compiled wrapper class instead of minting one per
      // component × slot.
      '#type' => 'inline_template',
      '#template' => '{% include _neo_slot_template %}',
      '#context' => $build + [
        '_neo_slot_template' => $template,
        'items' => $build,
        'slot' => [
          'name' => $this->getName(),
          'title' => $this->getTitle(),
        ],
        'neoIsPreview' => $this->component->isPreview(),
      ],
    ]);
  }

  /**
   * Returns where this slot's layout template lives, and whether it exists.
   *
   * The single source of truth behind the admin UI, the Drush inspector and
   * the development annotation, so none of them can name a different file.
   *
   * @return array
   *   An array with `path` (relative to the component directory), `exists`
   *   and `reference` (the resolved Twig path, or NULL).
   */
  public function getTemplateInfo(): array {
    $reference = $this->templateLocator?->getTemplate($this->component->getComponentId(), $this->name);
    return [
      'path' => ComponentSlotTemplateLocator::DIRECTORY . '/' . $this->name . '.twig',
      'exists' => $reference !== NULL,
      'reference' => $reference,
    ];
  }

  /**
   * Wraps a slot in a comment naming its layout template.
   *
   * Development environments only.
   *
   * @param array $build
   *   The slot's render array.
   *
   * @return array
   *   The annotated render array.
   */
  protected function annotateSlot(array $build): array {
    if (!$this->templateLocator?->isDevMode()) {
      return $build;
    }
    $info = $this->getTemplateInfo();
    // The variables the layout template can print. Read them from the resolved
    // keys rather than from $build, whose shape differs once it is wrapped.
    $items = array_map(fn($key) => '{{ ' . $key . ' }}', array_values($this->getKeys()));
    $open = '<!-- ' . Html::escape(sprintf(
      "NEO SLOT %s\n     layout: %s%s\n     items: %s",
      $this->name,
      $info['path'],
      $info['exists'] ? '' : ' (not created — add it to control this slot)',
      $items ? implode(', ', $items) : '(none)'
    )) . ' -->';
    $build['#prefix'] = Markup::create($open . self::filterExisting($build['#prefix'] ?? ''));
    $build['#suffix'] = Markup::create(self::filterExisting($build['#suffix'] ?? '') . '<!-- END NEO SLOT ' . Html::escape($this->name) . ' -->');
    return $build;
  }

  /**
   * Prepares one slot plugin's render array for theming and inspection.
   *
   * Adds the theme suggestions that let a component take over an item's
   * internals, and — in a development environment only — the HTML comment that
   * tells a frontend developer which template to create.
   *
   * @param array $child
   *   The plugin's render array.
   * @param string $key
   *   The item's resolved Twig key.
   *
   * @return array
   *   The prepared render array.
   */
  protected function prepareChild(array $child, string $key): array {
    // A '#lazy_builder' element may carry nothing but '#cache', '#weight',
    // '#create_placeholder' and '#preview' — Renderer::doRender() asserts it,
    // because every other property has to come from the callback. Block slots
    // routinely produce these. Adding a suggestion or a dev annotation here
    // would trip that assertion, and the properties would be discarded anyway.
    if (isset($child['#lazy_builder'])) {
      return $child;
    }
    $child = $this->expandChild($child);
    $suggestions = $this->getThemeSuggestions($child, $key);
    if ($suggestions) {
      // Most specific first: ThemeManager::render() walks the array and uses
      // the first hook that exists in the registry.
      $child['#theme'] = array_merge($suggestions, (array) $child['#theme']);
    }

    if ($this->templateLocator?->isDevMode()) {
      $child = $this->addDevAnnotation($child, $key, $suggestions);
    }

    return $child;
  }

  /**
   * Merges element info into a '#type' element so it carries a '#theme'.
   *
   * A bare ['#type' => 'pager'] has no '#theme' until element info is merged,
   * and would otherwise be unthemeable here. Merging early with '+=' is exactly
   * what Renderer::doRender() does, and it sets '#defaults_loaded', so the
   * renderer's own merge becomes a no-op rather than a second, conflicting
   * code path.
   *
   * @param array $child
   *   The plugin's render array.
   *
   * @return array
   *   The render array, with element defaults merged in.
   */
  protected function expandChild(array $child): array {
    if (isset($child['#type']) && !isset($child['#theme']) && $this->elementInfo) {
      $child += $this->elementInfo->getInfo($child['#type']);
    }
    return $child;
  }

  /**
   * Returns theming information about one slot item.
   *
   * The authoring contract for a slot item, in the form tooling needs it: what
   * the item is called in a slot template, which theme hook backs it, and which
   * template file would take over its markup.
   *
   * @param string $uuid
   *   The UUID of the slot plugin.
   *
   * @return array|null
   *   An array with `key`, `plugin_id`, `label`, `hook`, `suggestions`,
   *   `template`, `children`, `render_element` and `variables`, or NULL when
   *   the item renders nothing.
   */
  public function getItemInfo(string $uuid): ?array {
    $plugin = $this->getPlugin($uuid);
    if (!$plugin) {
      return NULL;
    }
    $child = $plugin->toRenderable();
    if (!is_array($child) || !$child) {
      return NULL;
    }
    $child = $this->expandChild($child);
    $key = $this->getKeys()[$uuid] ?? $plugin->getPluginId();
    $suggestions = $this->getThemeSuggestions($child, $key);
    $existing = (array) ($child['#theme'] ?? []);
    $hook = $existing ? (string) end($existing) : NULL;
    // What an override template is handed. A hook declares either a single
    // 'render element' (so the template prints {{ form }}, {{ element }}, …) or
    // a list of named 'variables' — the one thing an author cannot guess from
    // the filename.
    $registryEntry = ($hook && $this->themeRegistry) ? ($this->themeRegistry->get()[$hook] ?? NULL) : NULL;
    return [
      'key' => $key,
      'plugin_id' => $plugin->getPluginId(),
      'label' => $plugin->label(),
      'hook' => $hook,
      'suggestions' => $suggestions,
      'template' => $suggestions ? self::suggestionToFilename(reset($suggestions)) : NULL,
      'render_element' => $registryEntry['render element'] ?? NULL,
      'variables' => array_keys($registryEntry['variables'] ?? []),
      // The addressable sub-elements, e.g. an exposed form's filter
      // identifiers. Render array properties are not part of the contract, and
      // neither are access-denied children: Views hides form_build_id /
      // form_token / form_id on an exposed form, so listing them would invite
      // an author to place elements that render nothing.
      'children' => array_values(array_filter(
        array_keys($child),
        fn($k) => is_string($k)
          && !str_starts_with($k, '#')
          && (!is_array($child[$k]) || ($child[$k]['#access'] ?? TRUE) !== FALSE)
      )),
    ];
  }

  /**
   * Converts a theme suggestion into the template filename that implements it.
   *
   * @param string $suggestion
   *   The theme hook suggestion.
   *
   * @return string
   *   The path, relative to the component directory.
   */
  public static function suggestionToFilename(string $suggestion): string {
    return ComponentSlotTemplateLocator::DIRECTORY . '/' . str_replace('_', '-', $suggestion) . '.html.twig';
  }

  /**
   * Builds the theme suggestions for one slot item.
   *
   * @param array $child
   *   The plugin's render array, after element info has been merged.
   * @param string $key
   *   The item's resolved Twig key.
   *
   * @return string[]
   *   Suggestions, most specific first. Empty when the item has no '#theme'.
   */
  protected function getThemeSuggestions(array $child, string $key): array {
    if (empty($child['#theme'])) {
      return [];
    }
    $existing = (array) $child['#theme'];
    // Views hands us an array of suggestions with the generic hook last; that
    // generic hook is the one our own suggestions have to be built from.
    $base = (string) end($existing);
    if ($base === '') {
      return [];
    }
    $machine = $this->getComponentMachineName();
    return [
      $base . '__' . $machine . '__' . $this->name . '__' . $key,
      $base . '__' . $machine . '__' . $this->name,
    ];
  }

  /**
   * Wraps an item in an HTML comment describing how to theme it.
   *
   * Development environments only. Answers, from view-source alone, the two
   * questions a frontend developer actually has: what is this item called in
   * the slot template, and what file do I create to take over its markup.
   *
   * @param array $child
   *   The plugin's render array.
   * @param string $key
   *   The item's resolved Twig key.
   * @param string[] $suggestions
   *   The theme suggestions added to the item.
   *
   * @return array
   *   The annotated render array.
   */
  protected function addDevAnnotation(array $child, string $key, array $suggestions): array {
    $lines = [
      'NEO SLOT ' . $this->name . ' → ' . $key,
    ];
    if ($suggestions) {
      $lines[] = 'template: ' . self::suggestionToFilename(reset($suggestions));
    }
    else {
      $lines[] = 'template: item has no #theme hook — not overridable';
    }
    // Html::escape() also guarantees the content cannot contain '-->' and so
    // cannot break out of the comment.
    $open = '<!-- ' . Html::escape(implode("\n     ", $lines)) . ' -->';
    $close = '<!-- END NEO SLOT ' . Html::escape($this->name . ' → ' . $key) . ' -->';

    // Renderer::doRender() runs '#prefix'/'#suffix' through Xss::filterAdmin()
    // unless they are already safe markup, and that strips HTML comments — the
    // annotation would silently never appear. Mark our own comment safe, and
    // put any prefix the plugin had set through exactly the filtering it would
    // have received on its own, so wrapping it here cannot widen what is
    // allowed through.
    $child['#prefix'] = Markup::create($open . self::filterExisting($child['#prefix'] ?? ''));
    $child['#suffix'] = Markup::create(self::filterExisting($child['#suffix'] ?? '') . $close);
    return $child;
  }

  /**
   * Applies the filtering an affix would have received had we not wrapped it.
   *
   * @param mixed $value
   *   The existing '#prefix' or '#suffix' value.
   *
   * @return string
   *   The value, filtered unless it was already safe markup.
   *
   * @see \Drupal\Core\Render\Renderer::xssFilterAdminIfUnsafe()
   */
  private static function filterExisting($value): string {
    if ($value instanceof MarkupInterface) {
      return (string) $value;
    }
    return $value === '' ? '' : Xss::filterAdmin((string) $value);
  }

  /**
   * Returns the component's machine name, without its provider prefix.
   *
   * @return string
   *   The machine name, e.g. `list_insight` for `front:list_insight`.
   */
  protected function getComponentMachineName(): string {
    $id = $this->component->getComponentId();
    $pos = strrpos($id, ':');
    return $pos === FALSE ? $id : substr($id, $pos + 1);
  }

}
