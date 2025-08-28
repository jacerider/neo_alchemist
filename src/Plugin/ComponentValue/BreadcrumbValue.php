<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
final class BreadcrumbValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The breadcrumb manager.
   *
   * @var \Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface
   */
  protected $breadcrumbManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    BreadcrumbBuilderInterface $breadcrumb_manager,
    RouteMatchInterface $route_match,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->breadcrumbManager = $breadcrumb_manager;
    $this->routeMatch = $route_match;
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
      $container->get('current_route_match'),
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
        $uri = $url->toUriString();
        $value[] = [
          'title' => $link->getText(),
          'url' => [
            'title' => $link->getText(),
            'uri' => $uri === 'route:<none>' ? NULL : $uri,
            'options' => $options,
          ],
        ];
      }
    }
    if ($links) {
      if (!$this->configuration['hide_current']) {
        $request = \Drupal::request();
        $routeMatch = \Drupal::routeMatch();
        $route = $routeMatch->getRouteObject();
        if ($route) {
          $title = \Drupal::service('title_resolver')->getTitle($request, $route);
          $value[] = [
            'title' => $title,
            'url' => [],
          ];
        }
      }
    }
    return $value;
  }

}
