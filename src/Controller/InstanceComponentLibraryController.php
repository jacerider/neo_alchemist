<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\ComponentGroupPluginManager;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\neo_icon\IconTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Neo | Alchemist routes.
 */
final class InstanceComponentLibraryController extends ControllerBase {

  use IconTrait;

  /**
   * The component group plugin manager.
   *
   * @var \Drupal\neo_alchemist\ComponentGroupPluginManager
   */
  protected $componentGroupManager;

  /**
   * The controller constructor.
   */
  public function __construct(
    ComponentGroupPluginManager $componentGroupManager,
  ) {
    $this->componentGroupManager = $componentGroupManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('plugin.manager.neo_component_group'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(Request $request, ComponentTreeItem $neo_field) {
    /** @var \Drupal\neo_alchemist\ComponentStorage $storage */
    $storage = $this->entityTypeManager()->getStorage('neo_component');
    $query = [];
    $parent = $request->query->get('parent');
    $parentComponent = NULL;
    $parentShape = NULL;
    if ($parent) {
      $query['parent'] = $parent;
      [$parentUuid, $shapeId] = explode('--', (string) $parent);
      $parentComponent = $neo_field->getComponent($parentUuid);
      if ($parentComponent) {
        $parentShape = $parentComponent->getPropShapesAll(NULL, TRUE)[$shapeId] ?? NULL;
      }
    }
    if ($before = $request->query->get('before')) {
      $query['before'] = $before;
    }
    if ($after = $request->query->get('after')) {
      $query['after'] = $after;
    }

    $components = [];
    foreach (array_map(function ($component) use ($neo_field) {
      return $neo_field->createComponent($component);
    }, $storage->loadByEntity($neo_field->getEntity())) as $component) {
      if (!$component->access('create', NULL, FALSE, $parentShape)) {
        continue;
      }
      $group = $component->getGroup();
      $access = NULL;
      if ($instances = $component->getAccessInstances()) {
        $labels = [];
        foreach ($instances as $instance) {
          $labels[] = $instance->label();
        }
        $access['#markup'] = '<div class="badge bg-alert text-alert-content px-2">' . $this->adminIcon($this->t('Limited access by:') . ' ' . implode(', ', $labels), 'lock')->iconOnly() . '</div>';
      }
      $components[$group][$component->id()] = [
        'label' => $component->label(),
        'description' => $component->getDescription(),
        'thumbnail' => $component->getThumbnail(),
        'access' => $access,
        'attributes' => new Attribute([
          'href' => $neo_field->toUrl('add')->setRouteParameter('neo_component', $component->id())->setOption('query', $query)->toString(),
          'class' => ['use-ajax'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => '100%',
            'height' => '100%',
            'neo' => [
              'displaceTop' => '0px',
              'displaceBottom' => '0px',
              'contentPadding' => '0px',
            ],
          ]),
        ]),
      ];
    }

    $groups = [];
    foreach ($this->componentGroupManager->getDefinitions() as $group_id => $definition) {
      if (isset($components[$group_id])) {
        $groups[$group_id] = [
          'label' => $definition['label'],
          'description' => $definition['description'],
          'components' => $components[$group_id],
        ];
        uasort($groups[$group_id]['components'], function ($a, $b) {
          return strnatcasecmp($a['label'], $b['label']);
        });
      }
    }

    $build['library'] = [
      '#theme' => 'neo_alchemist_library',
      '#groups' => $groups,
      '#attached' => [
        'library' => ['core/drupal.dialog.ajax'],
      ],
    ];

    return $build;
  }

  /**
   * Returns the title.
   */
  public function getTitle(ComponentTreeItem $neo_field) {
    $label = $neo_field->belongsToFieldConfig() ? $neo_field->getEntity()->getEntityType()->getLabel() : $neo_field->getEntity()->label();
    return $this->t('Select the component to add to %label: %field_label', [
      '%label' => $label,
      '%field_label' => $neo_field->getFieldDefinition()->getLabel(),
    ]);
  }

}
