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

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentEditController extends ControllerBase {

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
  public function __invoke(ComponentInterface $neo_component) {
    $build = [
      '#theme' => 'neo_alchemist_manage',
      '#form_position' => 'side',
      '#id' => ComponentManageHelper::getId($neo_component),
      '#iframe_url' => $neo_component->toUrl('preview')->setOption('query', [
        'uuid' => $neo_component->uuid(),
        'component' => $neo_component->id(),
      ]),
      '#attached' => [
        'library' => ['neo_alchemist/component.parent'],
      ],
    ];

    $build['#form'] = $this->entityFormBuilder()->getForm($neo_component->getTargetEntity(), 'alchemist_edit', [
      'neo_component_instance' => $neo_component,
    ]);

    $build['#top_end'] = ComponentManageHelper::buildIframeOperations($neo_component);

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
    return $this->t('Edit %component from %label: %field_label', [
      '%component' => $neo_component->label(),
      '%label' => $label,
      '%field_label' => $neo_field->getFieldDefinition()->getLabel(),
    ]);
  }

}
