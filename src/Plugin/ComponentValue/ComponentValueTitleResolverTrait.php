<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * A trait for adding value matching capabilities to component value plugins.
 *
 * @see \Drupal\Core\Plugin\ContainerFactoryPluginInterface
 */
trait ComponentValueTitleResolverTrait {

  use DependencySerializationTrait;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected Request $request;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * The title resolver.
   *
   * @var \Drupal\Core\Controller\TitleResolverInterface
   */
  protected TitleResolverInterface $titleResolver;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    Request $request,
    RouteMatchInterface $route_match,
    TitleResolverInterface $title_resolver,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
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
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('current_route_match'),
      $container->get('title_resolver')
    );
  }

  /**
   * Gets the page title.
   */
  protected function getPageTitle(): mixed {
    $value = NULL;
    /** @var \Drupal\neo_alchemist\ComponentInterface $component */
    $component = $this->shape->getComponent();
    if ($component->isPreview()) {
      $value = '[Page Title]';
      $entity = $component->getTargetEntity();
      if ($entity && !$entity->isNew()) {
        $value = $entity->label();
      }
    }
    elseif ($title = $this->fetchPageTitle()) {
      $value = $title;
    }
    return $value;
  }

  /**
   * Fetches the page title.
   */
  private function fetchPageTitle(): mixed {
    $title = NULL;
    // Handle entity revision routes.
    if ($this->routeMatch->getRouteName() === 'entity.' . $this->shape->getEntity()->getEntityTypeId() . '.revision') {
      return $this->shape->getEntity()->label();
    }
    $route = $this->routeMatch->getRouteObject();
    if ($route) {
      $title = $this->titleResolver->getTitle($this->request, $route);
      if (is_array($title) && isset($title['#markup']) && is_string($title['#markup'])) {
        $title = $title['#markup'];
      }
      // A route title is markup, not a string: the common `$this->t('Edit
      // %label')` form renders `%label` as `<em class="placeholder">…</em>`,
      // and a TranslatableMarkup is exempt from Twig's autoescaping, so that
      // markup reaches the page verbatim. Every consumer of this value is a
      // plain-string prop — a heading, an anchor slug derived from one — which
      // then interpolates it into an attribute and breaks the tag. Flatten to
      // the text a reader would see.
      if ($title !== NULL) {
        $title = PlainTextOutput::renderFromHtml((string) $title);
      }
    }
    return $title;
  }

}
