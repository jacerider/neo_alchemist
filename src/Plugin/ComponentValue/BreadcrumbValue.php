<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\NonLinkingUri;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProducerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'breadcrumb',
  label: new TranslatableMarkup('Breadcrumb'),
  description: new TranslatableMarkup('Use a breadcrumb to populate link fields.'),
  group: 'providers',
  inline: TRUE,
  weight: 10,
  ref_types: [
    'breadcrumb',
  ],
)]
final class BreadcrumbValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface, ComponentValueProducerInterface {

  use ComponentValueTitleResolverTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * The breadcrumb manager.
   *
   * @var \Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface
   */
  protected $breadcrumbManager;

  /**
   * The route provider.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected RouteProviderInterface $routeProvider;

  /**
   * The router request context.
   *
   * @var \Drupal\Core\Routing\RequestContext
   */
  protected RequestContext $requestContext;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    BreadcrumbBuilderInterface $breadcrumb_manager,
    Request $request,
    RouteMatchInterface $route_match,
    TitleResolverInterface $title_resolver,
    RouteProviderInterface $route_provider,
    RequestContext $request_context,
    ConfigFactoryInterface $config_factory,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->breadcrumbManager = $breadcrumb_manager;
    $this->request = $request;
    $this->routeMatch = $route_match;
    $this->titleResolver = $title_resolver;
    $this->routeProvider = $route_provider;
    $this->requestContext = $request_context;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['shape'],
      $configuration['settings'],
      $container->get('breadcrumb'),
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('current_route_match'),
      $container->get('title_resolver'),
      $container->get('router.route_provider'),
      $container->get('router.request_context'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'hide_home' => FALSE,
      'hide_current' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The example crumbs are scaffolding for the editor preview. A page whose
   * breadcrumb genuinely has no links (the front page, a route the manager
   * builds nothing for) must render none, not the invented trail — and after
   * getDefaultValue() stopped letting an empty non-claiming producer wipe the
   * seeded example, claiming is the only way to say so.
   */
  protected function processingModeDefault(): string {
    return ComponentValueProcessingModeInterface::MODE_BLOCK;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {

    $form['hide_home'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide home'),
      '#description' => $this->t('If checked, the home page will not be included in the breadcrumb.'),
      '#default_value' => $this->configuration['hide_home'],
    ];

    $form['hide_current'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide current'),
      '#description' => $this->t('If checked, the current page will not be included in the breadcrumb.'),
      '#default_value' => $this->configuration['hide_current'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    $breadcrumb = $this->buildBreadcrumb();
    $value = [];
    $links = $breadcrumb->getLinks();
    // Asked before `hide_home` shifts the home crumb off. A page whose trail
    // is only Home still has a breadcrumb, and hiding home must not also
    // suppress the current page: read off the shifted array, the guard below
    // emptied the prop entirely on any page whose only crumb was Home.
    $has_links = (bool) $links;
    if ($links && $this->configuration['hide_home'] && $this->isFrontPageLink(reset($links))) {
      array_shift($links);
    }
    foreach ($links as $link) {
      /** @var \Drupal\Core\Link $link */
      $title = $link->getText();
      $url = $link->getUrl();
      if ($title && $url) {
        $options = $url->getOptions();
        $value[] = [
          'title' => $link->getText(),
          'url' => [
            'title' => $link->getText(),
            'uri' => NonLinkingUri::toUriString($url),
            'options' => $options,
          ],
        ];
      }
    }
    if ($has_links && !isset($links['_current'])) {
      if (!$this->configuration['hide_current']) {
        // Resolved through the shared title trait rather than the title
        // resolver directly. A route title can be a render array, and the
        // renderer refuses to render one outside a render context — which is
        // exactly where this runs: default values are computed while the SDC
        // plugin definitions are being rebuilt (ComponentPluginManager::
        // setCachedDefinitions() regenerates every component's expression),
        // and on a cache-cold request that happens during response
        // processing, long after rendering has finished. The trait flattens
        // to plain text instead, which is also what a crumb title wants.
        if ($title = $this->getPageTitle()) {
          $value['_current'] = [
            'title' => $title,
            'url' => [],
          ];
        }
      }
    }

    return $value;
  }

  /**
   * Whether a crumb points at the front page.
   *
   * "Hide home" used to mean "drop the first crumb", which holds only while
   * core's path-based builder is the one answering: it appends Home to every
   * trail it builds. A menu-based builder does not. menu_breadcrumb emits the
   * menu trail alone unless its `add_home` option is set, so on a site using
   * it the shift was eating a real ancestor — "About Us" off
   * `About Us / Leadership`, leaving the current page as the whole breadcrumb.
   *
   * @param \Drupal\Core\Link $link
   *   The first crumb in the trail.
   *
   * @return bool
   *   TRUE when the crumb is the front page.
   */
  private function isFrontPageLink(Link $link): bool {
    $url = $link->getUrl();
    if (!$url->isRouted()) {
      return FALSE;
    }
    if ($url->getRouteName() === '<front>') {
      return TRUE;
    }
    // A builder that leaves the front page as its own node route rather than
    // swapping in `<front>` is still pointing at the front page.
    $front = $this->configFactory->get('system.site')->get('page.front');
    return '/' . $url->getInternalPath() === $front;
  }

  /**
   * Builds the breadcrumb for the page this component will be rendered on.
   *
   * Live, that is the current request and the manager needs nothing else. In
   * a preview it is not: the editor renders the component from
   * `/node/12/alchemist/full/preview`, so the trail the manager builds is the
   * admin one the visitor will never see ("Leadership / Layout / Layout for
   * Leadership: Full") rather than the node's own. Point it at the host
   * entity instead, so the preview shows the trail the live page will.
   *
   * Both halves of the current request have to move together. The manager
   * asks each builder whether it ::applies() to a route match, and core's
   * path-based builder then ignores that route match entirely and walks the
   * path off the router request context. Swapping only one of the two would
   * leave the builders disagreeing about which page is being built.
   *
   * A builder that resolves the page from ambient state rather than from the
   * route match it is handed stays out of reach even so, and menu_breadcrumb
   * is the one to know about: its `derived_active_trail` option chooses
   * between building the active menu trail from the given route match and
   * reading the global menu.active_trail service. That service is a
   * CacheCollector whose cache id is pinned by the first lookup of the
   * request, which in a preview is the editor's own route, so nothing done
   * here can re-point it. With the option off such a preview falls through to
   * the path-based builder and shows that trail instead.
   *
   * @return \Drupal\Core\Breadcrumb\Breadcrumb
   *   The breadcrumb.
   */
  private function buildBreadcrumb(): Breadcrumb {
    $entity = $this->previewedEntity();
    if (!$entity) {
      return $this->breadcrumbManager->build($this->routeMatch);
    }
    $url = $entity->toUrl('canonical');
    // The front page has no breadcrumb, and the check core makes is against
    // the route match of the *current* request, which in a preview is the
    // editor's. Make it here instead, the same way PathMatcher does: compare
    // the internal path to the raw `page.front` value.
    $front = $this->configFactory->get('system.site')->get('page.front');
    if ('/' . $url->getInternalPath() === $front) {
      return new Breadcrumb();
    }
    $route_match = $this->routeMatchFor($url, $entity);
    // The aliased path, not the internal one: core walks the pre-alias
    // request path precisely so a hierarchy of aliases defines the trail.
    $original = $this->requestContext->getPathInfo();
    $this->requestContext->setPathInfo($url->toString());
    try {
      return $this->breadcrumbManager->build($route_match);
    }
    finally {
      // The request context is shared and the render continues after this,
      // so the swap has to be undone even if a builder throws.
      $this->requestContext->setPathInfo($original);
    }
  }

  /**
   * The saved entity a preview is standing in for, when there is one.
   *
   * A preview without a host entity, and one whose host is a placeholder the
   * component built to render against, both answer NULL: there is no page for
   * them to borrow a trail from, so the editor's own route remains the
   * truthful answer.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The entity, or NULL when the current route is the right one to use.
   */
  private function previewedEntity(): ?ContentEntityInterface {
    $component = $this->shape->getComponent();
    if (!$component->isPreview()) {
      return NULL;
    }
    $entity = $component->getTargetEntity();
    if ($entity->isNew() || !$entity->hasLinkTemplate('canonical')) {
      return NULL;
    }
    return $entity;
  }

  /**
   * A route match for an entity's canonical page.
   *
   * @param \Drupal\Core\Url $url
   *   The entity's canonical url.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity, passed upcast so a builder reading the route parameter gets
   *   the object a real request would have given it rather than an id.
   *
   * @return \Drupal\Core\Routing\RouteMatchInterface
   *   The route match.
   */
  private function routeMatchFor(Url $url, ContentEntityInterface $entity): RouteMatchInterface {
    $name = $url->getRouteName();
    $raw = $url->getRouteParameters();
    // RouteMatch drops whatever the route does not declare, so naming a
    // parameter the canonical route happens not to take costs nothing.
    return new RouteMatch(
      $name,
      $this->routeProvider->getRouteByName($name),
      [$entity->getEntityTypeId() => $entity] + $raw,
      $raw,
    );
  }

}
