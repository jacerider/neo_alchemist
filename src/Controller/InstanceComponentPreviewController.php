<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentPreviewController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer
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
      '#attached' => [
        'library' => [
          'neo_alchemist/instance.component.preview',
        ],
      ],
    ];
    $build['overlay'] = [
      '#theme' => 'neo_alchemist_overlay',
    ];
    $build['components'] = $neo_field->toRenderable();

    if (!empty($build['components'][ComponentTreeStructure::ROOT_UUID])) {
      foreach ($build['components'][ComponentTreeStructure::ROOT_UUID] as $uuid => &$componentBuild) {
        $component = $neo_field->getComponent($uuid);
        $data = [
          'uuid' => $uuid,
          'label' => $component->label(),
          'ops' => [
            'edit' => $component->access('update'),
            'delete' => $component->access('delete'),
            'sort' => $component->access('sort'),
            'clone' => $component->access('clone'),
            'add-before' => $component->access('create'),
            'add-after' => $component->access('create'),
          ],
        ];
        $componentBuild['#props']['attributes'] = new Attribute([
          'data-component' => Json::encode($data),
        ]);
      }
    }

    return $this->bareHtmlPageRenderer->renderBarePage($build, $this->getTitle($neo_field), 'page__neo_alchemist_preview', [
      '#attributes' => ['class' => ['!p-10']],
    ])
      ->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
  }

  /**
   * Builds the title.
   */
  public function getTitle(ComponentTreeItem $neo_field) {
    $label = $neo_field->belongsToFieldConfig() ? $neo_field->getEntity()->getEntityType()->getLabel() : $neo_field->getEntity()->label();
    return $this->t('Preview for %label: %field_label', [
      '%label' => $label,
      '%field_label' => $neo_field->getFieldDefinition()->getLabel(),
    ]);
  }

}
