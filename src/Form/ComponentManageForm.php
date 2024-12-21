<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_icon\IconTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class ComponentManageForm extends EntityForm {

  use IconTranslationTrait;

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

    $form['iframe'] = [
      '#type' => 'html_tag',
      '#tag' => 'iframe',
      '#attributes' => [
        'src' => $this->entity->toUrl('preview')->toString(),
        'width' => '100%',
        'height' => '300px',
        'frameborder' => '0',
        'class' => [
          'border-2',
        ],
      ],
    ];

    $form['props'] = [
      '#type' => 'table',
      '#attached' => ['library' => ['core/drupal.dialog.ajax']],
      '#tree' => TRUE,
      '#header' => [
        'property' => $this->t('Property'),
        'type' => $this->t('Type'),
        'required' => $this->t('Required'),
        'editable' => $this->t('Editable'),
        // 'value_providers' => $this->t('Value Providers'),
        // 'value_modifiers' => $this->t('Value Modifiers'),
        'operations' => '',
      ],
      '#neo_style' => [
        'property' => 'heading',
      ],
      '#neo_size' => [
        'required' => 'min',
        'editable' => 'min',
      ],
      '#neo_align' => [
        'required' => 'center',
        'editable' => 'center',
      ],
    ];

    foreach ($this->entity->getPropShapes() as $propName => $shape) {
      $row = [];
      $row['property']['#markup'] = $shape->getTitle() . ' <small>(' . $shape->getName() . ')</small>';
      $row['type']['#markup'] = $shape->getType() . ' <small>(' . $shape->getRef() . ')</small>';
      $row['required']['#markup'] = $shape->isRequired() ? $this->icon($this->t('Yes'))->iconOnly() : $this->icon($this->t('No'))->iconOnly();
      $row['editable']['#markup'] = $shape->isEditable() ? $this->icon($this->t('Yes'))->iconOnly() : $this->icon($this->t('No'))->iconOnly();
      // $instances = $shape->getValueCollection()->getActiveInstances();
      // $row['value_providers']['#markup'] = implode(', ', array_map(function ($provider) {
      //   return $provider->label();
      // }, $shape->getValueCollection()->getValueProviders()));
      // $row['value_modifiers']['#markup'] = implode(', ', array_map(function ($provider) {
      //   return $provider->label();
      // }, $shape->getValueModifiers()));

      $links = [];
      $links['edit'] = [
        'title' => $this->t('Customize'),
        'url' => $this->entity->toUrl('edit-prop-form')->setRouteParameter('prop', $propName),
        // 'attributes' => [
        //   'class' => ['use-ajax'],
        //   'data-dialog-type' => 'modal',
        //   'data-dialog-options' => Json::encode([
        //     'width' => '100%',
        //     'height' => '100%',
        //   ]),
        // ],
      ];
      $row['operations'] = [
        '#type' => 'operations',
        '#links' => $links,
      ];

      $form['props'][$propName] = $row;
    }

    $thumbnailId = $this->entity->getThumbnailId();
    $form['thumbnail'] = [
      '#type' => 'neo_config_file',
      '#title' => $this->t('Thumbnail'),
      '#extensions' => ['png'],
      // '#upload_location' => 'public://neo-alchemist/components',
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

    if ($this->entity->getTargetEntityTypeId()) {
      $form['entity_preview'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('Select a preview entity'),
        '#description' => $this->t('Select an entity to use when previewing this component. This setting is specific to the current site environment.'),
        '#target_type' => $this->entity->getTargetEntityTypeId(),
        '#default_value' => $this->entity->getTargetPreviewEntity(),
        '#selection_handler' => 'default',
        '#selection_settings' => [
          'target_bundles' => [$this->entity->getTargetEntityBundle()],
        ],
      ];
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
      // '#multiple' => TRUE,
    ];
    // ksm($permissions_by_provider);

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
  public function save(array $form, FormStateInterface $form_state): int {
    if ($entityPreview = $form_state->getValue('entity_preview')) {
      $this->entity->setTargetPreviewEntity($entityPreview);
    }
    $result = parent::save($form, $form_state);
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
