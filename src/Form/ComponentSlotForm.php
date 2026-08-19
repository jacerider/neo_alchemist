<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_alchemist\Ajax\ComponentAjaxFormHelperTrait;
use Drupal\neo_alchemist\ComponentManageHelper;
use Drupal\neo_alchemist\ComponentSlot;
use Drupal\neo_alchemist\ComponentSlotPluginInterface;
use Drupal\neo_alchemist\ComponentSlotPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Component form.
 */
final class ComponentSlotForm extends EntityForm {

  use ComponentAjaxFormHelperTrait;
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

    $keys = $this->slot->getKeys();

    $form['list'] = [
      '#type' => 'table',
      '#header' => [
        'label' => $this->t('Title'),
        'key' => $this->t('Twig key'),
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
        '#markup' => '<span title="' . Html::escape($uuid) . '">' . Html::escape($plugin->label()) . '</span>',
      ];

      // The name a slot template addresses this item by. Left empty it falls
      // back to the plugin id, which is what the placeholder shows; the
      // description always states the key that will actually be rendered,
      // including any `_2` suffix a collision with a sibling resolved to.
      $row['key'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Twig key'),
        '#title_display' => 'invisible',
        '#size' => 16,
        '#default_value' => $this->slot->getKey($uuid) ?? '',
        '#placeholder' => $plugin->getPluginId(),
        '#description' => $this->t('Renders as <code>{{ @key }}</code>', [
          '@key' => $keys[$uuid] ?? $plugin->getPluginId(),
        ]),
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
        '#title' => $this->t('Weight'),
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

    $options = array_map(fn($definition) => $definition['label'], $this->slotManager->getFilteredDefinitionsFromComponent($this->entity));
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

    $form['theming'] = $this->buildThemingHelp($plugins);

    return $form;
  }

  /**
   * Builds the reference telling a themer which files control this slot.
   *
   * Neither template announces itself in the markup: the layout template is
   * pulled in by {% include %} and so never reaches the theme system, and an
   * item override only appears in core's FILE NAME SUGGESTIONS once it exists.
   * Without this panel the filenames are only discoverable from
   * `drush neo:alchemist:slot`, which is not where a site builder is looking.
   *
   * @param \Drupal\neo_alchemist\ComponentSlotPluginInterface[] $plugins
   *   The slot's plugins, keyed by UUID.
   *
   * @return array
   *   A render array.
   */
  private function buildThemingHelp(array $plugins): array {
    $info = $this->slot->getTemplateInfo();

    // No extra access check: reaching this form already means passing the
    // route's _neo_component_slot check, and everything below is public
    // knowledge about the theme layer, not data.
    $element = [
      '#type' => 'details',
      '#title' => $this->t('Theming this slot'),
      '#open' => FALSE,
    ];

    $element['layout'] = [
      '#type' => 'inline_template',
      '#template' => '<p>{{ intro }}</p><p><code>{{ path }}</code> {{ status }}</p>',
      '#context' => [
        'intro' => $this->t('Arrange the items below by adding this file to the component directory:'),
        'path' => $info['path'],
        'status' => $info['exists']
          ? $this->t('— in use.')
          : $this->t('— not created yet.'),
      ],
    ];

    // The layout template's own context. Its items are listed first because
    // they are the reason to write one; the rest are fixed for every slot.
    $context = [];
    foreach ($this->slot->getKeys() as $key) {
      $context[] = [
        ['data' => self::codeCell('{{ ' . $key . ' }}')],
        $this->t('One item in this slot.'),
      ];
    }
    $context[] = [
      ['data' => self::codeCell('{{ items }}')],
      $this->t("Every item, keyed as above. Use <code>items|without('key')</code> for the ones this template does not place itself."),
    ];
    $context[] = [
      ['data' => self::codeCell('{{ slot }}')],
      $this->t('This slot: <code>slot.name</code>, <code>slot.title</code>.'),
    ];
    $context[] = [
      ['data' => self::codeCell('{{ neoIsPreview }}')],
      $this->t('TRUE inside the Alchemist editor preview.'),
    ];
    $element['context'] = [
      '#type' => 'table',
      '#caption' => $this->t('Variables in <code>@path</code>. The component’s own props are <em>not</em> available — print each item exactly once.', ['@path' => $info['path']]),
      '#header' => [$this->t('Variable'), $this->t('What it is')],
      '#rows' => $context,
    ];

    $rows = [];
    foreach (array_keys($plugins) as $uuid) {
      $item = $this->slot->getItemInfo($uuid);
      if (!$item) {
        continue;
      }
      if (!$item['template']) {
        $none = (string) $this->t('This item has no theme hook, so its internals cannot be overridden.');
        $rows[] = [
          ['data' => self::codeCell('{{ ' . $item['key'] . ' }}')],
          ['data' => ['#markup' => $none], 'colspan' => 2],
        ];
        continue;
      }
      $rows[] = [
        ['data' => self::codeCell('{{ ' . $item['key'] . ' }}')],
        ['data' => self::codeCell($item['template'])],
        ['data' => ['#markup' => $this->describeItemVariables($item)]],
      ];
    }

    if ($rows) {
      $element['items'] = [
        '#type' => 'table',
        '#caption' => $this->t('To control one item’s own markup instead of just its placement, add its override template. It inherits that theme hook’s variables and preprocessing, and any wrapper — such as a form’s &lt;form&gt; tag — is still applied around whatever it outputs.'),
        '#header' => [
          $this->t('Item'),
          $this->t('Override template'),
          $this->t('Variables inside it'),
        ],
        '#rows' => $rows,
      ];
    }

    $element['note'] = [
      '#markup' => '<p>' . $this->t('Paths are relative to the component directory. Run <code>drush cr</code> after adding a template, unless the Neo dev server is running.') . '</p>',
    ];

    return $element;
  }

  /**
   * Describes what an item's override template receives.
   *
   * A theme hook hands its template either one 'render element' variable or a
   * list of named ones, and that is the thing an author cannot guess from the
   * filename. Where the element has addressable children — an exposed form's
   * filter identifiers, say — they are spelled out too, since those are what
   * the template is being written to rearrange.
   *
   * @param array $item
   *   An item as returned by ComponentSlot::getItemInfo().
   *
   * @return string
   *   An HTML fragment.
   */
  private function describeItemVariables(array $item): string {
    $out = [];
    if ($item['render_element']) {
      $out[] = '<code>{{ ' . Html::escape($item['render_element']) . ' }}</code>';
      if ($item['children']) {
        $children = array_map(
          fn($child) => '<code>' . Html::escape($item['render_element'] . '.' . $child) . '</code>',
          $item['children']
        );
        $out[] = '<br /><small>' . implode(' ', $children) . '</small>';
      }
    }
    elseif ($item['variables']) {
      $out[] = implode(' ', array_map(
        fn($name) => '<code>{{ ' . Html::escape($name) . ' }}</code>',
        $item['variables']
      ));
    }
    else {
      $out[] = '<small>' . (string) $this->t('Unknown — run <code>drush neo:alchemist:slot</code>.') . '</small>';
    }
    return implode('', $out);
  }

  /**
   * Renders a copy-pasteable value in a table cell.
   *
   * @param string $value
   *   The value to show.
   *
   * @return array
   *   A render array.
   */
  private static function codeCell(string $value): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<code>{{ value }}</code>',
      '#context' => ['value' => $value],
    ];
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
        $plugin->submitConfigurationForm($form['plugins']['form']['settings'], $subform_state);
      }
    }
    elseif ($op === 'update') {
      $plugin = $this->slot->getPlugin($uuid);
      $form_state->set('new', FALSE);
      $subform_state = SubformState::createForSubform($form['plugins']['form']['settings'], $form, $form_state);
      $plugin->validateConfigurationForm($form['plugins']['form']['settings'], $subform_state);
      $plugin->setConfiguration($subform_state->getValues());
      $plugin->submitConfigurationForm($form['plugins']['form']['settings'], $subform_state);
      $form_state->set('uuid', NULL);
      $form_state->set('op', 'list');
    }

    // An empty slot builds its list table with #access FALSE, and a table is a
    // form input: with no rows to write into it and no user input allowed, it
    // falls back to the '' every input element defaults to. So the key exists
    // and ?? does not catch it — adding the very first plugin to a slot would
    // otherwise hand a string to the array handling below.
    $rows = $values['plugins']['list'] ?? [];
    $rows = is_array($rows) ? $rows : [];

    // Must run before ::toArray() below. The line that reorders 'plugins' keeps
    // only the submitted row ORDER and replaces every row value with the
    // plugin's own settings, so anything typed into a row — the Twig key
    // included — is discarded there unless it has already been folded into the
    // slot.
    if (!$this->applyPluginKeys($form, $form_state, $rows)) {
      return;
    }

    // Extract any settings and update the component entity.
    $settings = $this->slot->toArray();
    if ($rows) {
      $settings['plugins'] = array_replace(array_intersect_key($rows, $settings['plugins']), $settings['plugins']);
    }
    $this->entity->setSlotSettings($this->slot, $settings);
  }

  /**
   * Validates the submitted Twig keys and folds them into the slot.
   *
   * A bad key cannot be allowed to reach config. ComponentSlot::getKeys()
   * ignores an unusable stored value at render time, but silently rendering
   * under a different name than the one somebody typed is its own bug, so the
   * form is where a human finds out.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $rows
   *   The submitted 'plugins.list' values, keyed by plugin UUID.
   *
   * @return bool
   *   FALSE when an error was set and the caller should stop.
   */
  private function applyPluginKeys(array $form, FormStateInterface $form_state, array $rows): bool {
    $valid = TRUE;
    $seen = [];
    foreach ($rows as $uuid => $row) {
      if (!is_array($row) || !array_key_exists('key', $row)) {
        continue;
      }
      $key = trim((string) $row['key']);
      $element = $form['plugins']['list'][$uuid]['key'] ?? NULL;
      if ($key !== '') {
        if (!preg_match(ComponentSlot::KEY_PATTERN, $key)) {
          if ($element) {
            $form_state->setError($element, $this->t('The Twig key %key must start with a lowercase letter and contain only lowercase letters, numbers and underscores.', ['%key' => $key]));
          }
          $valid = FALSE;
          continue;
        }
        if (isset($seen[$key])) {
          if ($element) {
            $form_state->setError($element, $this->t('The Twig key %key is already used by another item in this slot.', ['%key' => $key]));
          }
          $valid = FALSE;
          continue;
        }
        $seen[$key] = TRUE;
      }
      // An empty value clears the override and restores the derived key.
      $this->slot->setKey($uuid, $key !== '' ? $key : NULL);
    }
    return $valid;
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
