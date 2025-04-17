<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\neo_alchemist\Plugin\DataType\ComponentTreeStructure;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_icon\IconTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentPreviewController extends ControllerBase {

  use IconTranslationTrait;

  /**
   * Private temporary storage.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $store;

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly BareHtmlPageRendererInterface $bareHtmlPageRenderer,
    PrivateTempStoreFactory $temp_store_factory,
  ) {
    $this->store = $temp_store_factory->get('neo_alchemist');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_component_page_renderer'),
      $container->get('tempstore.private')
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(Request $request, ComponentTreeItem $neo_field) {
    // Render in preview mode.
    $neo_field->setPreview(TRUE);
    if ($uuid = $request->query->get('uuid')) {
      $build = $this->single($neo_field, $uuid, $request->query->get('component'));
    }
    else {
      $build = $this->all($neo_field);
    }
    return $this->bareHtmlPageRenderer->renderBarePage($build, $this->getTitle($neo_field), 'page__neo_alchemist_preview')->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
  }

  /**
   * Preview for a single component.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $neo_field
   *   The component field.
   * @param string $uuid
   *   The component UUID.
   * @param string $component
   *   The component name.
   *
   * @return array
   *   The render array.
   */
  protected function single(ComponentTreeItem $neo_field, string $uuid, string $component) {
    if (!$neo_field->hasComponent($uuid)) {
      $neo_field->addComponent($uuid, $component);
    }

    if ($data = $this->store->get($neo_field->getDraftKey($uuid))) {
      $neo_field->updateComponent($uuid, $data);
    }

    $component = $neo_field->getComponent($uuid);
    return [
      '#theme' => 'neo_alchemist_component_preview',
      '#attached' => [
        'library' => [
          'neo_alchemist/component.child',
        ],
      ],
      'component' => $component->toRenderable(),
    ];
  }

  /**
   * Preview for all components.
   *
   * @param \Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem $neo_field
   *   The component field.
   *
   * @return array
   *   The render array.
   */
  protected function all(ComponentTreeItem $neo_field) {
    $build = [
      '#attached' => [
        'library' => [
          'neo_alchemist/components.child',
        ],
      ],
    ];
    $build['overlay'] = [
      '#theme' => 'neo_alchemist_overlay',
    ];

    $build['components'] = $neo_field->toRenderable();
    $build['components']['#theme'] = 'neo_alchemist_component_preview';

    if (!$neo_field->getComponents()) {
      $build['components']['empty'] = [
        '#type' => 'inline_template',
        '#template' => '<div class="text-xs p-3 bg-base-100 text-base-700 border border-dashed text-center">{{ icon("info-circle") }}{{ empty_message }}</div>',
        '#context' => [
          'empty_message' => $this->t('No components have been added to this layout. Use the <strong>@add</strong> button in the footer to add a component.', [
            '@add' => $this->icon('Add', 'plus'),
          ]),
        ],
      ];
    }

    if (!empty($build['components'][ComponentTreeStructure::ROOT_UUID])) {
      foreach ($build['components'][ComponentTreeStructure::ROOT_UUID] as $uuid => &$componentBuild) {
        $component = $neo_field->getComponent($uuid);
        $data = [
          'label' => $component->label(),
          'status' => $component->isPublished(),
          'ops' => [
            'edit' => $component->access('update'),
            'delete' => $component->access('delete'),
            'sort' => $component->access('sort'),
            'clone' => $component->access('clone'),
            'add-before' => $component->access('create'),
            'add-after' => $component->access('create'),
          ],
        ];

        $componentBuild['#props']['attributes']->addClass('[&>*]:pointer-events-none');
        $componentBuild['#props']['attributes']->addClass(!$component->isPublished() ? 'opacity-50' : '');
        $componentBuild['#props']['attributes']->setAttribute('data-component-uuid', $uuid);
        $componentBuild['#props']['attributes']->setAttribute('data-component', Json::encode($data));
      }
    }
    return $build;
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
