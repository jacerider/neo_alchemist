<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentAddController extends ControllerBase {

  use AjaxHelperTrait;

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_component_page_renderer'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(Request $request, ComponentTreeItem $neo_field, ComponentInterface $neo_component) {
    $parent = $request->query->get('parent');
    [$parentUuid, $shapeId] = explode('--', (string) ($parent ?? '--'));

    $instance = $neo_field->createComponent($neo_component, $parentUuid, $shapeId);

    $build = [
      '#theme' => 'neo_alchemist_manage',
      '#form_position' => 'side',
      '#id' => ComponentManageHelper::getId($instance),
      '#iframe_url' => $instance->toUrl('preview')->setOption('query', [
        'uuid' => $instance->uuid(),
        'component' => $instance->id(),
      ]),
      '#attached' => [
        'library' => ['neo_alchemist/component.parent'],
      ],
    ];

    $build['#form'] = $this->entityFormBuilder()->getForm($instance->getTargetEntity(), 'alchemist', [
      'neo_component_instance' => $instance,
      'parent' => $parent,
      'before' => $request->query->get('before'),
      'after' => $request->query->get('after'),
    ]);

    $build['#top_end'] = ComponentManageHelper::buildIframeOperations($instance);

    if ($this->isAjax()) {
      return $build;
    }

    return $this->bareHtmlPageRenderer->renderBarePage($build, 'Manage: ' . $neo_component->label(), 'back');
  }

  /**
   * Returns the title.
   */
  public function getTitle(ComponentTreeItem $neo_field, ComponentInterface $neo_component) {
    $label = $neo_field->belongsToFieldConfig() ? $neo_field->getEntity()->getEntityType()->getLabel() : $neo_field->getEntity()->label();
    return $this->t('Add %component to %label: %field_label', [
      '%component' => $neo_component->label(),
      '%label' => $label,
      '%field_label' => $neo_field->getFieldDefinition()->getLabel(),
    ]);
  }

}
