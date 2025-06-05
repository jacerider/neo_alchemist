<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_icon\IconTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentManageController extends ControllerBase {

  use IconTrait;

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
      $container->get('neo_component_page_renderer')
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(ComponentTreeItem $neo_field) {
    $build = [
      '#type' => 'neo_alchemist_manage',
      '#neo_field' => $neo_field,
    ];
    return $this->bareHtmlPageRenderer->renderBarePage($build, $this->getTitle($neo_field), 'back');
  }

  /**
   * Builds the title.
   */
  public function getTitle(ComponentTreeItem $neo_field) {
    $label = $neo_field->belongsToFieldConfig() ? $neo_field->getEntity()->getEntityType()->getLabel() : $neo_field->getEntity()->label();
    return $this->t('@for for %label: %field_label', [
      '@for' => $neo_field->belongsToFieldConfig() ? 'Default layout' : 'Layout',
      '%label' => $label,
      '%field_label' => $neo_field->getFieldDefinition()->getLabel(),
    ]);
  }

}
