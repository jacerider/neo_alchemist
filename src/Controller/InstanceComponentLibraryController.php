<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Template\Attribute;
use Drupal\neo_alchemist\ComponentGroupPluginManager;
use Drupal\neo_alchemist\ComponentSubgroupResolver;
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
   * The component sub-group resolver.
   *
   * @var \Drupal\neo_alchemist\ComponentSubgroupResolver
   */
  protected ComponentSubgroupResolver $subgroupResolver;

  /**
   * The controller constructor.
   */
  public function __construct(
    ComponentGroupPluginManager $componentGroupManager,
    ComponentSubgroupResolver $subgroupResolver,
  ) {
    $this->componentGroupManager = $componentGroupManager;
    $this->subgroupResolver = $subgroupResolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('plugin.manager.neo_component_group'),
      $container->get('neo_alchemist.component_subgroup'),
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
    $parentUuid = NULL;
    $shapeId = NULL;
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

    // When targeting a region, create the candidates with their parent set so
    // access('create') evaluates the real target (hybrid mode restricts
    // creation to entity-customizable regions).
    $createParentUuid = NULL;
    $createShapeId = NULL;
    if ($parentShape && $parentShape->getRef() === 'region') {
      $createParentUuid = $parentUuid;
      $createShapeId = $shapeId;
    }

    $components = [];
    $subgroups = [];
    foreach (array_map(function ($component) use ($neo_field, $createParentUuid, $createShapeId) {
      return $neo_field->createComponent($component, $createParentUuid, $createShapeId);
    }, $storage->loadByEntity($neo_field->getEntity())) as $component) {
      if (!$component->access('create', NULL, FALSE, $parentShape)) {
        continue;
      }
      $group = $component->getGroup();
      if ($subgroup = $this->subgroupResolver->resolve($component)) {
        $subgroups[$group][$subgroup['id']] ??= [
          'label' => $subgroup['label'],
          'sort' => $subgroup['sort'],
          'components' => [],
        ];
        // Keyed by component id so the sorted group list can be filtered down
        // with array_intersect_key(), preserving the label sort.
        $subgroups[$group][$subgroup['id']]['components'][$component->id()] = TRUE;
      }
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

    $sortByLabel = function ($a, $b) {
      return strnatcasecmp($a['label'], $b['label']);
    };

    $groups = [];
    foreach ($this->componentGroupManager->getDefinitions() as $group_id => $definition) {
      // PHP casts a numeric-looking array key to int, so normalise before it
      // reaches a string type hint.
      $group_id = (string) $group_id;
      if (!isset($components[$group_id])) {
        continue;
      }
      $groups[$group_id] = [
        'label' => $definition['label'],
        'description' => $definition['description'],
        'components' => $components[$group_id],
      ];
      uasort($groups[$group_id]['components'], $sortByLabel);

      // Split a sub-grouped group by target entity — but only when more than
      // one sub-group survives. The library is already filtered to the host
      // entity, so on a real content page a single "Content › Project"
      // heading would just add noise; on a field's default layout, where
      // several bundles can qualify, the headings earn their place.
      if (!$this->subgroupResolver->hasSubgroups($group_id) || count($subgroups[$group_id] ?? []) < 2) {
        continue;
      }
      $subgrouped = $this->subgroupResolver->sortSubgroups($subgroups[$group_id]);
      foreach ($subgrouped as $subgroup_id => $subgroup) {
        $subgrouped[$subgroup_id]['components'] = array_intersect_key($groups[$group_id]['components'], $subgroup['components']);
      }
      $groups[$group_id]['subgroups'] = $subgrouped;
    }

    if (!$groups && !$neo_field->belongsToFieldConfig() && $neo_field->getFieldDefinition()->isHybrid()) {
      // An empty library in hybrid mode has two unrelated causes, so explain
      // the right one: either the target isn't an entity-customizable region
      // at all, or it is a valid region but no component qualifies for it
      // (e.g. none support the region's allowed component sizes).
      $message = $neo_field->isCustomTarget($parentUuid, $shapeId)
        ? $this->t('No components are available to add to this region.')
        : $this->t('Components can only be added within a customizable region of this layout.');
      return [
        'empty' => [
          '#markup' => '<div class="card p-3">' . $message . '</div>',
        ],
      ];
    }

    $build['form'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['neo-alchemist-library-form card p-3 mb-6']],
    ];

    $build['form']['search'] = [
      '#type' => 'search',
      '#attributes' => ['class' => ['neo-alchemist-library-search']],
      '#placeholder' => $this->t('Search components...'),
    ];

    $build['library'] = [
      '#theme' => 'neo_alchemist_library',
      '#groups' => $groups,
      '#attached' => [
        'library' => [
          'core/drupal.dialog.ajax',
          'neo_alchemist/component.library',
        ],
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
