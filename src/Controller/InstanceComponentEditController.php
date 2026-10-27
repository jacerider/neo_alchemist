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
  public function __invoke(Request $request, ComponentInterface $neo_component) {
    // The inspector: the form alone, loaded into the layout editor's right
    // dock (components-parent.ts). Its preview is that editor's own canvas,
    // so nothing here renders frames.
    if ($this->isAjax() && $request->query->get('dock')) {
      $form = $this->entityFormBuilder()->getForm($neo_component->getTargetEntity(), 'alchemist_edit', [
        'neo_component_instance' => $neo_component,
        'neo_alchemist_dock' => TRUE,
      ]);
      $form['#attributes']['class'][] = 'neo-alchemist-manage--form';
      return $form;
    }

    // Opened on its own: the same inspector, docked beside a canvas of the
    // component.
    $build = [
      '#theme' => 'neo_alchemist_manage',
      '#form_position' => 'dock',
      '#dock' => TRUE,
      '#id' => ComponentManageHelper::getId($neo_component),
      '#toolbar_title' => ComponentManageHelper::getToolbarTitle($neo_component),
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

    $build['#preview'] = ComponentManageHelper::buildIframeOperations($neo_component);

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
