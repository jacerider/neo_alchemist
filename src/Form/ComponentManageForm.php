<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\neo_alchemist\ComponentShapeStylePluginInterface;
use Drupal\neo_icon\IconTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class ComponentManageForm extends EntityForm {

  use IconTrait;

  /**
   * The entity.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $entity;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * PatternEditForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity manager service.
   */
  public function __construct(EntityTypeBundleInfoInterface $entity_type_bundle_info, EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $form_state->set('neo_component_form', TRUE);

    $form += $this->buildPropsForm($form, $form_state);
    $form += $this->buildSlotsForm($form, $form_state);
    $form += $this->buildFiltersForm($form, $form_state);
    $form += $this->buildAccessForm($form, $form_state);

    $thumbnailId = $this->entity->getThumbnailId();
    $form['thumbnail'] = [
      '#type' => 'neo_config_file',
      '#title' => $this->t('Thumbnail'),
      '#filename' => Html::getClass('component-' . $this->entity->id()),
      '#extensions' => ['png'],
      '#dependencies' => [
        $this->entity->getConfigDependencyKey() => [
          $this->entity->getConfigDependencyName(),
        ],
      ],
      '#default_value' => $thumbnailId,
    ];
    if (!$thumbnailId && ($thumbnail = $this->entity->getDefaultThumbnail())) {
      $form['thumbnail']['#field_prefix'] = [
        '#theme' => 'image',
        '#uri' => $thumbnail,
        '#attributes' => [
          'style' => 'display: block; max-width: 80px; max-height: 80px',
        ],
        '#prefix' => '<div class="flex items-center justify-center pr-2">',
        '#suffix' => '</div>',
      ];
    }

    if (!$thumbnailId) {
      $form['thumbnail_generate'] = [
        '#type' => 'button',
        '#value' => $this->t('Capture Automatic Thumbnail'),
        '#id' => 'neo-alchemist-thumbnail-generate-button',
        '#attributes' => [
          'class' => ['btn', 'btn-xs', 'mt-2'],
        ],
      ];
      $form['thumbnail_generate_data'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Thumbnail Data'),
        '#id' => 'neo-alchemist-thumbnail-generate-data',
        '#wrapper_attributes' => [
          'class' => ['hidden'],
        ],
      ];
    }

    if ($this->entity->getTargetEntityTypeId()) {
      $form['entity_preview'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Select a preview entity'),
        '#description' => $this->t('Select an entity to use when previewing this component. This setting is specific to the current site environment.'),
        '#target_type' => $this->entity->getTargetEntityTypeId(),
        '#default_value' => $this->entity->getTargetPreviewEntity(),
        '#selection_handler' => 'default',
      ];
      if ($target_bundle = $this->entity->getTargetEntityBundle()) {
        $form['entity_preview']['#selection_settings']['target_bundles'] = [$target_bundle];
      }
    }

    $permissions = \Drupal::service('user.permissions')->getPermissions();
    $permissions_by_provider = [];
    foreach ($permissions as $permission_name => $permission) {
      $permissions_by_provider[$permission['provider']][$permission_name] = $permission['title'];
    }
    $permissions_by_provider = ['neo_alchemist' => $permissions_by_provider['neo_alchemist']] + $permissions_by_provider;
    $form['permission'] = [
      '#type' => 'select',
      '#title' => $this->t('Permission'),
      '#description' => $this->t('Select the permission required to manage this component. If no permission is selected the component will be available to all users who can make updates to the entity the component is attached to.'),
      '#options' => $permissions_by_provider,
      '#empty_option' => $this->t('- Select -'),
      // @todo Add permission support.
      '#access' => FALSE,
    ];

    return $form;
  }

  /**
   * Build value props form.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form.
   */
  protected function buildPropsForm(array $form, FormStateInterface $form_state): array {
    $shapes = array_filter($this->entity->getPropShapes(), fn ($shape) => $shape->access('manage_value'));
    if ($shapes) {
      $form['props'] = [
        '#type' => 'table',
        '#caption' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['flex', 'items-center']],
          'title' => [
            '#markup' => $this->t('Value Props') . ($this->entity->isAggregate() ? ' (' . $this->t('aggregated') . ')' : ''),
          ],
          'add' => [
            '#type' => 'link',
            '#title' => $this->entity->isAggregate() ? $this->t('Disable Aggregation') : $this->t('Enable Aggregation'),
            '#url' => $this->entity->toUrl('aggregate-form'),
            '#attributes' => [
              'class' => ['use-ajax', 'btn btn-xs ml-auto'],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => Json::encode([
                // 'width' => '100%',
                // 'height' => '100%',
                'neo' => [
                  'displaceTop' => '0px',
                  'displaceBottom' => '0px',
                ],
              ]),
            ],
          ],
        ],
        '#attached' => ['library' => ['core/drupal.dialog.ajax']],
        '#tree' => TRUE,
        '#header' => [
          'property' => $this->t('Property'),
          'type' => $this->t('Type'),
          'value_providers' => $this->t('Value Providers'),
          'value_modifiers' => $this->t('Value Modifiers'),
          'active' => $this->t('Active'),
          'editable' => $this->t('Editable'),
          'required' => $this->t('Required'),
          'operations' => '',
        ],
        '#neo_style' => [
          'property' => 'heading',
          'type' => 'xs',
          'value_providers' => 'xs',
          'value_modifiers' => 'xs',
        ],
        '#neo_size' => [
          'active' => 'min',
          'editable' => 'min',
          'required' => 'min',
        ],
        '#neo_align' => [
          'active' => 'center',
          'editable' => 'center',
          'required' => 'center',
        ],
      ];
      $form['styles'] = [
        '#caption' => $this->t('Style Props'),
      ] + $form['props'];

      foreach ($shapes as $propName => $shape) {
        $row = [];
        $row['property']['#markup'] = $shape->getTitle() . ' <small>(' . $shape->getName() . ')</small>';
        $row['type']['#markup'] = $shape->getType() . ' (' . $shape->getRef() . ')';
        $plugins = $shape->getValueCollection()->getActiveInstances();
        $row['value_providers']['#markup'] = implode(', ', array_map(function ($provider) {
          return $provider->label();
        }, array_filter($plugins, fn ($plugin) => $plugin->getGroup() === 'providers')));
        $row['value_modifiers']['#markup'] = implode(', ', array_map(function ($provider) {
          return $provider->label();
        }, array_filter($plugins, fn ($plugin) => $plugin->getGroup() === 'modifiers')));

        $isActive = $shape->isActive();
        $row['active']['#markup'] = $this->statusIcon($isActive, 'Yes', 'No')->iconOnly();
        if (!$isActive) {
          $row['editable']['#markup'] = $this->icon($this->t('No'), 'ban')->iconOnly();
          $row['required']['#markup'] = $this->icon($this->t('No'), 'ban')->iconOnly();
        }
        else {
          if ($shape->isLocked()) {
            $row['editable']['#markup'] = $this->icon($this->t('No'), 'ban')->iconOnly();
          }
          else {
            $row['editable']['#markup'] = $this->statusIcon($shape->isEditable(), 'Yes', 'No')->iconOnly();
          }
          $row['required']['#markup'] = $this->statusIcon($shape->isRequired(), 'Yes', 'No')->iconOnly();
        }

        $links = [];
        $links['edit'] = [
          'title' => $this->t('Customize'),
          'url' => $this->entity->toUrl('edit-prop-form')->setRouteParameter('prop', $propName),
          'attributes' => [
            'class' => ['use-ajax'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => Json::encode([
              'width' => '100%',
              'height' => '100%',
              'neo' => [
                'displaceTop' => '0px',
                'displaceBottom' => '0px',
              ],
            ]),
          ],
        ];
        $row['operations'] = [
          '#type' => 'operations',
          '#links' => $links,
        ];
        if ($shape instanceof ComponentShapeStylePluginInterface) {
          $form['styles'][$propName] = $row;
        }
        else {
          $form['props'][$propName] = $row;
        }
      }
      $form['props']['#access'] = !empty(Element::children($form['props']));
      $form['styles']['#access'] = !empty(Element::children($form['styles']));
    }
    return $form;
  }

  /**
   * Build value slots form.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form.
   */
  protected function buildSlotsForm(array $form, FormStateInterface $form_state): array {
    $slots = $this->entity->getSlots();
    if ($slots) {
      $form['slots'] = [
        '#type' => 'table',
        '#caption' => $this->t('Slots'),
        '#attached' => ['library' => ['core/drupal.dialog.ajax']],
        '#tree' => TRUE,
        '#header' => [
          'property' => $this->t('Slot'),
          'plugins' => $this->t('Plugins'),
          'operations' => '',
        ],
        '#neo_style' => [
          'property' => 'heading',
          'plugins' => 'xs',
        ],
        '#neo_size' => [
          'editable' => 'min',
          'required' => 'min',
        ],
      ];

      foreach ($slots as $slotName => $slot) {
        $row = [];
        $row['property']['#markup'] = $slot->getTitle() . ' <small>(' . $slot->getName() . ')</small>';

        $row['plugins']['#markup'] = implode(', ', array_map(function ($plugin) {
          return $plugin->label();
        }, $slot->getPlugins()));

        $links = [];
        $links['edit'] = [
          'title' => $this->t('Customize'),
          'url' => $this->entity->toUrl('edit-slot-form')->setRouteParameter('slot', $slotName),
          'attributes' => [
            'class' => ['use-ajax'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => Json::encode([
              'width' => '100%',
              'height' => '100%',
              'neo' => [
                'displaceTop' => '0px',
                'displaceBottom' => '0px',
              ],
            ]),
          ],
        ];
        $row['operations'] = [
          '#type' => 'operations',
          '#links' => $links,
        ];

        $form['slots'][$slotName] = $row;
      }
    }
    return $form;
  }

  /**
   * Build value props form.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form.
   */
  protected function buildFiltersForm(array $form, FormStateInterface $form_state): array {
    $filters = $this->entity->getFilters();
    $form['filters'] = [
      '#type' => 'table',
      '#caption' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['flex', 'items-center']],
        'title' => [
          '#markup' => $this->t('Filters'),
        ],
        'add' => [
          '#type' => 'link',
          '#title' => $this->t('Add Filter'),
          '#url' => $this->entity->toUrl('add-filter-form'),
          '#attributes' => [
            'class' => ['use-ajax', 'btn btn-xs ml-auto'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => Json::encode([
              'width' => '100%',
              'height' => '100%',
              'neo' => [
                'displaceTop' => '0px',
                'displaceBottom' => '0px',
              ],
            ]),
          ],
        ],
      ],
      '#empty' => $this->t('No filters have been added yet.'),
      '#attached' => ['library' => ['core/drupal.dialog.ajax']],
      '#tree' => TRUE,
      '#header' => [
        'property' => $this->t('Filter'),
        'plugin' => $this->t('Plugin'),
        'editable' => $this->t('Editable'),
        'required' => $this->t('Required'),
        'operations' => '',
      ],
      '#neo_style' => [
        'property' => 'heading',
        'plugin' => 'xs',
      ],
      '#neo_size' => [
        'operations' => 'min',
        'editable' => 'min',
        'required' => 'min',
      ],
      '#neo_align' => [
        'editable' => 'center',
        'required' => 'center',
      ],
    ];

    foreach ($filters as $uuid => $filter) {
      $row = [];
      $row['property']['#markup'] = $filter->label();
      $row['plugin'] = [];
      $summary = $filter->settingsSummary();
      if (!empty($summary)) {
        $row['plugin'] = [
          '#type' => 'inline_template',
          '#template' => '<div class="slot-plugin-summary">{{ summary|safe_join("<br />") }}</div>',
          '#context' => ['summary' => $summary],
        ];
      }
      $row['editable']['#markup'] = $filter->isEditable() ? $this->icon($this->t('Yes'))->iconOnly() : $this->icon($this->t('No'))->iconOnly();
      $row['required']['#markup'] = $filter->isRequired() ? $this->icon($this->t('Yes'))->iconOnly() : $this->icon($this->t('No'))->iconOnly();

      $links = [];
      $links['edit'] = [
        'title' => $this->t('Customize'),
        'url' => $this->entity->toUrl('edit-filter-form')->setRouteParameter('uuid', $uuid),
        'attributes' => [
          'class' => ['use-ajax'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => '100%',
            'height' => '100%',
            'neo' => [
              'displaceTop' => '0px',
              'displaceBottom' => '0px',
            ],
          ]),
        ],
      ];
      $row['operations'] = [
        '#type' => 'operations',
        '#links' => $links,
      ];

      $form['filters'][$uuid] = $row;
    }

    return $form;
  }

  /**
   * Build value props form.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The form.
   */
  protected function buildAccessForm(array $form, FormStateInterface $form_state): array {
    $instances = $this->entity->getAccessInstances();
    $form['access'] = [
      '#type' => 'table',
      '#caption' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['flex', 'items-center']],
        'title' => [
          '#markup' => $this->t('Access'),
        ],
        'add' => [
          '#type' => 'link',
          '#title' => $this->t('Add Access'),
          '#url' => $this->entity->toUrl('add-access-form'),
          '#attributes' => [
            'class' => ['use-ajax', 'btn btn-xs ml-auto'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => Json::encode([
              'width' => '100%',
              'height' => '100%',
              'neo' => [
                'displaceTop' => '0px',
                'displaceBottom' => '0px',
              ],
            ]),
          ],
        ],
      ],
      '#empty' => $this->t('No accesss have been added yet.'),
      '#attached' => ['library' => ['core/drupal.dialog.ajax']],
      '#tree' => TRUE,
      '#header' => [
        'property' => $this->t('Access'),
        'summary' => $this->t('Summary'),
        'operations' => '',
      ],
      '#neo_style' => [
        'property' => 'heading',
        'summary' => 'xs',
      ],
      '#neo_size' => [
        'operations' => 'min',
        'editable' => 'min',
        'required' => 'min',
      ],
      '#neo_align' => [
        'editable' => 'center',
        'required' => 'center',
      ],
    ];

    foreach ($instances as $uuid => $access) {
      $row = [];
      $row['property']['#markup'] = $access->label();
      $row['summary'] = [];
      $summary = $access->settingsSummary();
      if (!empty($summary)) {
        $row['summary'] = [
          '#type' => 'inline_template',
          '#template' => '<div class="slot-plugin-summary">{{ summary|safe_join("<br />") }}</div>',
          '#context' => ['summary' => $summary],
        ];
      }

      $links = [];
      $links['edit'] = [
        'title' => $this->t('Customize'),
        'url' => $this->entity->toUrl('edit-access-form')->setRouteParameter('uuid', $uuid),
        'attributes' => [
          'class' => ['use-ajax'],
          'data-dialog-type' => 'modal',
          'data-dialog-options' => Json::encode([
            'width' => '100%',
            'height' => '100%',
            'neo' => [
              'displaceTop' => '0px',
              'displaceBottom' => '0px',
            ],
          ]),
        ],
      ];
      $row['operations'] = [
        '#type' => 'operations',
        '#links' => $links,
      ];

      $form['access'][$uuid] = $row;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = [];
    $actions['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#submit' => ['::submitForm', '::save'],
    ];
    $actions['reset'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reset'),
      '#limit_validation_errors' => [],
      '#submit' => ['::submitForm', '::reset'],
    ];
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  protected function copyFormValuesToEntity(EntityInterface $entity, array $form, FormStateInterface $form_state) {
    $form_state->unsetValue('props');
    $form_state->unsetValue('slots');
    $form_state->unsetValue('filters');
    $form_state->unsetValue('access');
    parent::copyFormValuesToEntity($entity, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    if ($entityPreview = $form_state->getValue('entity_preview')) {
      $this->entity->setTargetPreviewEntity($entityPreview);
    }

    // Save the generated thumbnail.
    $thumbnailData = trim($form_state->getValue('thumbnail_generate_data', ''));
    if (!empty($thumbnailData)) {
      $data = explode(',', $thumbnailData);
      if (!empty($data[1])) {
        /** @var \Drupal\neo_config_file\ConfigFileGenerator $generator */
        $generator = \Drupal::service('neo_config_file.generator');
        $configFile = $generator->createFromBase64($data[1], 'component-' . str_replace('_', '-', $this->entity->id()) . '.png', 500, 320, 500, 320);
        if ($configFile) {
          $this->entity->set('thumbnail', $configFile->id());
        }
      }
    }

    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Updated component %label.', ['%label' => $this->entity->label()]));
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function reset(array $form, FormStateInterface $form_state): int {
    $this->entity->setSetting('props', []);
    $result = parent::save($form, $form_state);
    return $result;
  }

}
