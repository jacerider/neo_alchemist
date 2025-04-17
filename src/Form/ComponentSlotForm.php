<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_alchemist\Ajax\InstanceIframeHelper;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\ComponentSlotPluginInterface;
use Drupal\neo_alchemist\ComponentSlotPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class ComponentSlotForm extends EntityForm {

  use InstanceIframeHelper;
  use StringTranslationTrait;

  /**
   * The entity.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $entity;

  /**
   * The slot manager.
   *
   * @var \Drupal\neo_alchemist\ComponentSlotPluginManager
   */
  protected $slotManager;

  /**
   * The slot.
   *
   * @var \Drupal\neo_alchemist\ComponentSlotInterface
   */
  protected $slot;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.neo_component_slot'),
    );
  }

  /**
   * ComponentSlotForm constructor.
   *
   * @param \Drupal\neo_alchemist\ComponentSlotPluginManager $slot_manager
   *   The slot manager.
   */
  public function __construct(ComponentSlotPluginManager $slot_manager) {
    $this->slotManager = $slot_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $slot = NULL) {
    $form_state->set('neo_component_manage_id', ComponentManageHelper::getId($this->entity));
    $this->slot = $this->entity->getSlot($slot);
    $form['#title'] = $this->t('Edit %prop_label from %label', [
      '%prop_label' => $this->slot->getTitle(),
      '%label' => $this->entity->label(),
    ]);
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $id = Html::getId('neo-component-manage-' . $this->entity->id());
    $uuid = $form_state->get('uuid');
    $op = $form_state->get('op');

    $form['plugins'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#parents' => ['plugins'],
      '#attributes' => [
        'id' => $id,
      ],
    ];

    switch ($op) {
      case 'add':
      case 'edit':
        if ($uuid) {
          $plugin = $this->slot->getPlugin($uuid);
        }
        elseif ($pluginId = $form_state->get('plugin_id')) {
          $plugin = $this->slotManager->createInstance($pluginId);
        }
        else {
          throw new \Exception('Invalid plugin');
        }
        $form['plugins'] = $this->buildPluginEditForm($form['plugins'], $form_state, $id, $plugin);
        break;

      default:
        $form['plugins'] = $this->buildPluginListForm($form['plugins'], $form_state, $id);
        break;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  private function buildPluginEditForm(array $form, FormStateInterface $form_state, string $id, ComponentSlotPluginInterface $plugin): array {
    $isNew = (bool) $form_state->get('new');
    $form['form'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('@op %label', [
        '@op' => $isNew ? 'Edit' : 'Add',
        '%label' => $plugin->label(),
      ]),
      '#parents' => ['plugins', 'form'],
    ];

    $form['form']['settings'] = [
      '#type' => 'container',
      '#parents' => array_merge($form['#parents'], ['settings']),
    ];
    $subform_state = SubformState::createForSubform($form['form']['settings'], $form, $form_state);
    $form['form']['settings'] = $plugin->buildConfigurationForm($form['form']['settings'], $subform_state, $form);

    $form['form']['actions'] = [
      '#type' => 'actions',
    ];
    $form['form']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $isNew ? $this->t('Create') : $this->t('Update'),
      '#op' => 'update',
      '#uuid' => $plugin->uuid(),
      '#plugin' => $plugin->getPluginId(),
      '#submit' => ['::submitRebuild'],
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $id,
      ],
    ];
    $form['form']['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#op' => $isNew ? 'remove' : 'list',
      '#submit' => ['::SubmitRebuild'],
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $id,
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  private function buildPluginListForm(array $form, FormStateInterface $form_state, string $id): array {
    $plugins = $this->slot->getPlugins();

    $form['list'] = [
      '#type' => 'table',
      '#header' => [
        'label' => $this->t('Title'),
        'summary' => $this->t('Summary'),
        'ops' => '',
        'weight' => $this->t('Weight'),
      ],
      '#access' => !empty($plugins),
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'draggable-weight',
        ],
      ],
    ];

    $weight = 0;
    foreach ($plugins as $uuid => $plugin) {
      $row = [];
      $row['#attributes']['class'] = ['draggable'];
      $row['label'] = [
        '#markup' => $plugin->label() . ' <small>(' . $uuid . ')</small>',
      ];

      $row['summary'] = [];
      $summary = $plugin->settingsSummary();
      if (!empty($summary)) {
        $row['summary'] = [
          '#type' => 'inline_template',
          '#template' => '<small class="slot-plugin-summary">{{ summary|safe_join("<br />") }}</small>',
          '#context' => ['summary' => $summary],
        ];
      }

      $row['ops'] = [
        '#neo_size' => 'min',
      ];
      $row['ops']['edit'] = [
        '#type' => 'submit',
        '#name' => 'edit-' . $uuid,
        '#op' => 'edit',
        '#uuid' => $uuid,
        '#value' => $this->t('Edit'),
        '#submit' => ['::submitRebuild'],
        '#ajax' => [
          'callback' => [static::class, 'refreshAjax'],
          'wrapper' => $id,
        ],
      ];
      $row['ops']['remove'] = [
        '#type' => 'submit',
        '#name' => 'remove-' . $uuid,
        '#op' => 'remove',
        '#uuid' => $uuid,
        '#value' => $this->t('Remove'),
        '#submit' => ['::submitRebuild'],
        '#ajax' => [
          'callback' => [static::class, 'refreshAjax'],
          'wrapper' => $id,
        ],
      ];
      $row['weight'] = [
        '#type' => 'weight',
        '#title' => t('Weight'),
        '#title_display' => 'invisible',
        '#default_value' => $weight,
        '#attributes' => [
          'class' => [
            'draggable-weight',
          ],
        ],
      ];
      $weight++;
      $form['list'][$uuid] = $row;
    }

    $options = array_map(fn($definition) => $definition['label'], $this->slotManager->getDefinitions());
    asort($options);
    $form['add'] = [
      '#type' => 'select',
      '#title' => $this->t('Add Plugin'),
      '#op' => 'add',
      '#options' => $options,
      '#empty_option' => $this->t('- Select -'),
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $id,
      ],
    ];
    return $form;
  }

  /**
   * Ajax callback.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    return $form['plugins'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    if ($form_state->getErrors()) {
      return;
    }
    $trigger = $form_state->getTriggeringElement();
    $values = $form_state->getValues();
    $op = $trigger['#op'] ?? $form_state->get('op');
    $uuid = $trigger['#uuid'] ?? $form_state->get('uuid');
    $plugin_id = $trigger['#plugin'] ?? $form_state->getValue(['plugins', 'add']) ?? $form_state->get('plugin_id');
    $form_state->set('op', $op);
    $form_state->set('uuid', $uuid);
    $form_state->set('plugin_id', $plugin_id);
    if ($op === 'remove' && $uuid) {
      $this->slot->removePlugin($uuid);
      $form_state->set('uuid', NULL);
      $form_state->set('op', 'list');
    }
    elseif ($op === 'add' || $op === 'edit') {
      $pendingUuid = $form_state->get('uuid');
      if ($pendingUuid) {
        $plugin = $this->slot->getPlugin($pendingUuid);
      }
      else {
        $plugin = $this->slot->addPlugin($plugin_id);
        $form_state->set('uuid', $plugin->uuid());
        $form_state->set('new', TRUE);
      }
      if ($plugin && !empty($form['plugins']['form']['settings'])) {
        // When in add, we do update settings as plugin may have ajax
        // functionality that needs this.
        $subform_state = SubformState::createForSubform($form['plugins']['form']['settings'], $form, $form_state);
        $plugin->validateConfigurationForm($form['plugins']['form']['settings'], $subform_state);
        $plugin->setConfiguration($subform_state->getValues());
      }
    }
    elseif ($op === 'update') {
      $plugin = $this->slot->getPlugin($uuid);
      $form_state->set('new', FALSE);
      $subform_state = SubformState::createForSubform($form['plugins']['form']['settings'], $form, $form_state);
      $plugin->validateConfigurationForm($form['plugins']['form']['settings'], $subform_state);
      $plugin->setConfiguration($subform_state->getValues());
      $form_state->set('uuid', NULL);
      $form_state->set('op', 'list');
    }

    // Extract any settings and update the component entity.
    $settings = $this->slot->toArray();
    if (!empty($values['plugins']['list'])) {
      $settings['plugins'] = array_replace(array_intersect_key($values['plugins']['list'], $settings['plugins']), $settings['plugins']);
    }
    $this->entity->setSlotSettings($this->slot, $settings);
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#submit' => ['::submitForm', '::save'],
    ];
    if ($this->isAjax()) {
      $actions['#attached']['library'][] = 'neo_alchemist/component.ajax';
      $actions['submit']['#ajax']['callback'] = '::ajaxSubmit';
    }
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function submitRebuild(array $form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function submitCancel(array $form, FormStateInterface $form_state) {
    $form_state->set('op', 'list');
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Updated slot %label.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

}
