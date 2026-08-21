<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
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
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->breadcrumbManager = $breadcrumb_manager;
    $this->request = $request;
    $this->routeMatch = $route_match;
    $this->titleResolver = $title_resolver;
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
    $breadcrumb = $this->breadcrumbManager->build($this->routeMatch);
    $value = [];
    $links = $breadcrumb->getLinks();
    if ($links && $this->configuration['hide_home']) {
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
    if ($links && !isset($links['_current'])) {
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

}
