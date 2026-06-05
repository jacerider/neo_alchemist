<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist_library\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Theme\ComponentPluginManager;
use Drupal\neo_alchemist_library\Registry\ComponentInstaller;
use Drupal\neo_alchemist_library\Registry\RegistryClient;
use Drupal\neo_alchemist_library\Registry\RegistryException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders a live preview of a registry component before it is installed.
 *
 * The component is briefly materialized as a submodule-owned SDC, rendered
 * through neo_alchemist's standard preview pipeline (transient neo_component
 * entity → example prop values → bare front-theme page), then removed.
 */
final class ComponentPreviewController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly RegistryClient $registryClient,
    private readonly ComponentInstaller $installer,
    private readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer,
    private readonly ComponentPluginManager $componentPluginManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_alchemist_library.registry_client'),
      $container->get('neo_alchemist_library.component_installer'),
      $container->get('neo_component_page_renderer'),
      $container->get('plugin.manager.sdc'),
    );
  }

  /**
   * Builds the bare preview page (used as an iframe document).
   */
  public function __invoke(string $component) {
    try {
      if (!$this->registryClient->has($component)) {
        throw new NotFoundHttpException();
      }
      $handle = $this->installer->materializePreview($component);
    }
    catch (RegistryException) {
      throw new NotFoundHttpException();
    }

    // Ensure the materialized files are removed even on a fatal error.
    $cleaned = FALSE;
    $cleanup = function () use ($handle, &$cleaned): void {
      if (!$cleaned) {
        $cleaned = TRUE;
        $this->installer->cleanupPreview($handle);
      }
    };
    register_shutdown_function($cleanup);

    try {
      $componentId = $handle['componentId'];
      $definition = $this->componentPluginManager->hasDefinition($componentId)
        ? $this->componentPluginManager->getDefinition($componentId)
        : NULL;
      if (!$definition) {
        throw new NotFoundHttpException();
      }

      /** @var \Drupal\neo_alchemist\ComponentInterface $entity */
      $entity = $this->entityTypeManager()->getStorage('neo_component')->create([
        'id' => 'sdc_preview_' . $handle['previewId'],
        'label' => $definition['name'] ?? $component,
        'component' => $componentId,
        'status' => TRUE,
      ]);
      $entity->setPreview(TRUE);

      $build = [
        '#theme' => 'neo_alchemist_component_preview',
        '#attached' => [
          'library' => [
            'neo_alchemist/component.child',
            // Tailwind v4 browser JIT: styles any class the compiled CSS lacks.
            'neo_alchemist_library/tailwind_jit',
          ],
          'html_head' => array_filter([
            [
              [
                '#tag' => 'style',
                '#attributes' => ['type' => 'text/tailwindcss'],
                '#value' => $this->buildJitConfigCss(),
              ],
              'neo_alchemist_library_tailwind_jit_config',
            ],
            // Static safelist of neo color + component-spacing utilities (plain
            // CSS, no @theme so the :root variables are never clobbered).
            [
              [
                '#tag' => 'style',
                '#value' => trim($this->buildNeoColorUtilityCss() . "\n" . $this->buildNeoSpacingUtilityCss()),
              ],
              'neo_alchemist_library_neo_utilities',
            ],
          ]),
        ],
        'component' => $entity->toRenderable(),
      ];

      // renderBarePage renders the component to HTML here, so cleanup afterwards
      // is safe — the twig has already been read from disk.
      return $this->bareHtmlPageRenderer
        ->renderBarePage($build, 'Preview: ' . ($definition['name'] ?? $component), 'front')
        ->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
    }
    finally {
      $cleanup();
    }
  }

  /**
   * Builds the inline Tailwind config for the browser JIT.
   *
   * Emits only the utilities layer so the JIT fills in standard utility classes
   * a brand-new component uses that the compiled `front.css` hasn't scanned yet
   * (spacing, grid, sizing, arbitrary values, etc.). front.css already provides
   * base/components and the full neo color palette.
   *
   * We deliberately do NOT declare an `@theme` color block: Tailwind v4 emits
   * `:root,:host { --color-*: rgb(var(--color-*)) }` for theme colors, which is
   * self-referential against neo_color's real triplet variables and would
   * clobber every color on the page. The neo palette utilities the component
   * actually uses are already compiled into front.css and render from there;
   * any palette utility not present can be added via buildNeoColorUtilityCss().
   */
  protected function buildJitConfigCss(): string {
    // Import the default theme (breakpoints, spacing, sizing) + utilities so the
    // JIT can generate responsive variants (md:/lg:) and scale utilities a
    // brand-new component uses. The default theme intentionally has no
    // base/primary/secondary/accent palette, so neo_color's triplet variables
    // are NOT clobbered; neo palette utilities come from front.css +
    // buildNeoColorUtilityCss().
    return "@import \"tailwindcss/theme\" layer(theme);\n@import \"tailwindcss/utilities\" layer(utilities);\n";
  }

  /**
   * Builds a static safelist of neo_color palette utilities (plain CSS).
   *
   * Injected as ordinary CSS (not `@theme`), so it never redefines the `:root`
   * color variables — it only adds `.bg-/.text-/.border-<pallet>-<shade>` rules
   * referencing the existing triplet vars. This guarantees neo color utilities
   * render in the preview even if a brand-new component uses a shade the
   * compiled CSS never scanned. Returns '' when neo_color is unavailable.
   */
  protected function buildNeoColorUtilityCss(): string {
    if (!$this->moduleHandler()->moduleExists('neo_color')) {
      return '';
    }
    try {
      $pallets = array_keys($this->entityTypeManager()->getStorage('neo_pallet')->loadByProperties(['status' => 1]));
    }
    catch (\Throwable) {
      return '';
    }
    $rules = [];
    foreach ($pallets as $id) {
      foreach ([0, 50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950] as $shade) {
        $var = sprintf('rgb(var(--color-%s-%d))', $id, $shade);
        $rules[] = sprintf('.bg-%1$s-%2$d{background-color:%3$s}.text-%1$s-%2$d{color:%3$s}.border-%1$s-%2$d{border-color:%3$s}', $id, $shade, $var);
      }
    }
    return implode("\n", $rules);
  }

  /**
   * Builds a static safelist of neo's `component` spacing-scale utilities.
   *
   * The browser JIT's default theme doesn't know neo_alchemist's custom
   * `component` spacing scale (registered via its NeoBuildEventSubscriber), so
   * utilities like `gap-component` / `my-component` / `p-component` never
   * generate. We emit them as plain CSS referencing `var(--spacing-component)`,
   * which the compiled `.component-spacing` wrapper class already sets
   * responsively on the page. Mirrors the scale formulas in
   * neo_alchemist's NeoBuildEventSubscriber::onBuild().
   */
  protected function buildNeoSpacingUtilityCss(): string {
    $values = [
      'component' => 'var(--spacing-component, var(--spacing))',
      'component-xs' => 'calc(var(--spacing-component, var(--spacing)) / 3)',
      'component-sm' => 'calc(var(--spacing-component, var(--spacing)) / 1.5)',
      'component-lg' => 'calc(var(--spacing-component, var(--spacing)) * 1.5)',
      'component-xl' => 'calc(var(--spacing-component, var(--spacing)) * 3)',
    ];
    $props = [
      'p' => 'padding', 'px' => 'padding-inline', 'py' => 'padding-block',
      'pt' => 'padding-top', 'pr' => 'padding-right', 'pb' => 'padding-bottom', 'pl' => 'padding-left',
      'm' => 'margin', 'mx' => 'margin-inline', 'my' => 'margin-block',
      'mt' => 'margin-top', 'mr' => 'margin-right', 'mb' => 'margin-bottom', 'ml' => 'margin-left',
      'gap' => 'gap', 'gap-x' => 'column-gap', 'gap-y' => 'row-gap',
    ];
    $rules = [];
    foreach ($values as $token => $value) {
      foreach ($props as $prefix => $prop) {
        $rules[] = sprintf('.%s-%s{%s:%s}', $prefix, $token, $prop, $value);
      }
    }
    return implode("\n", $rules);
  }

}
