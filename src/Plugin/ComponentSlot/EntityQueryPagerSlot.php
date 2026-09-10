<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentSlot;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentSlot;
use Drupal\neo_alchemist\ComponentInterface;
use Drupal\neo_alchemist\Slot\ComponentSlotPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_component_slot.
 */
#[ComponentSlot(
  id: 'entity_query_pager',
  label: new TranslatableMarkup('Entity Query | Pager'),
  description: new TranslatableMarkup('Embed a pager for an entity query.'),
)]
final class EntityQueryPagerSlot extends ComponentSlotPluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The pager manager.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected PagerManagerInterface $pagerManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentInterface $component,
    string $uuid,
    array $configuration,
    PagerManagerInterface $pagerManager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $component, $uuid, $configuration);
    $this->pagerManager = $pagerManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['component'],
      $configuration['uuid'],
      $configuration['settings'],
      $container->get('pager.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'context' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * The paging-enabled props, as context id => shape title.
   *
   * Only a value provider that actually paginated registers this context, so
   * this list is by construction the set of props this slot can page.
   */
  protected function getOptions(): array {
    $options = [];
    foreach ($this->component->getPropShapeContexts('entity_query_pager') as $context => $contextInfo) {
      $options[$context] = $contextInfo['shape']->getTitle();
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    $context = $this->configuration['context'];
    $summary[] = $this->t('Entity Query: @context', [
      '@context' => $context
        ? ($this->getOptions()[$context] ?? $this->t('MISSING'))
        : $this->t('Automatic'),
    ]);
    return $summary;
  }

  /**
   * Configuration form for the slot plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form = parent::configurationForm($form, $form_state, $complete_form);
    if ($options = $this->getOptions()) {
      $form['context'] = [
        '#type' => 'select',
        '#title' => $this->t('Entity Query'),
        '#description' => $this->t('Which paging-enabled query prop this pager drives.'),
        '#options' => $options,
        // Deliberately not required, and an empty value means "the first one".
        // Config saved before this select existed carries no context at all,
        // and every such slot drove the component's single query.
        '#empty_option' => $this->t('- Automatic -'),
        '#default_value' => $this->configuration['context'],
      ];
    }
    else {
      $form['context_none'] = [
        '#type' => 'item',
        '#markup' => $this->t('No prop on this component runs a query with paging enabled, so this slot renders nothing. Enable paging on the query, or remove this item.'),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function toRenderable() {
    $contexts = $this->component->getPropShapeContexts('entity_query_pager');
    if (!$contexts) {
      // Nothing on this component paginates. Return an empty array rather than
      // a bare ['#type' => 'pager'], which renders pager element 0 — whoever
      // created it — and which ComponentSlot::toRenderable() would keep as a
      // filled slot, suppressing the component's own {% block %} fallback.
      // This is the state a slot is left in when the prop behind it switches
      // to a provider that does not page.
      return [];
    }

    $context = $this->configuration['context'];
    if ($context !== '') {
      // Configured but gone: the prop was renamed, or its provider swapped.
      // Render nothing rather than fall back to another list's pager.
      if (!isset($contexts[$context])) {
        return [];
      }
    }
    else {
      // Config predating the select drove the component's single query.
      $context = array_key_first($contexts);
    }
    $element = (int) $contexts[$context]['value'];

    // The provider registers its element when the query is BUILT; the pager
    // itself only exists once the query has run. A query that was never
    // executed has no pager, and '#type' => 'pager' would emit an empty but
    // truthy wrapper for it.
    if ($this->pagerManager->getPager($element) === NULL) {
      return [];
    }

    // Pager::preRenderPager() adds this too, but declaring it here puts it on
    // the component's metadata before applyTo() runs rather than relying on
    // render-time bubbling reaching the right parent.
    $this->addCacheableDependency(
      (new CacheableMetadata())->addCacheContexts(['url.query_args.pagers:' . $element])
    );

    return [
      '#type' => 'pager',
      // Not the element-info default of 0: a query paginated after anything
      // else on the page is on element 1, 2, and so on.
      '#element' => $element,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentInterface $component): bool {
    // Deliberately permissive: this only narrows the "add plugin" picker, and
    // toRenderable() is the authority on whether anything renders. An
    // entity-query prop qualifies even with paging still switched off, so
    // placing the slot first and ticking "Enable paging" second works. A prop
    // already registering a pager qualifies however it got there — an event
    // subscriber running its own paged query, for instance.
    return $component->hasPropShapeWithPlugin('entity_query')
      || $component->getPropShapeContexts('entity_query_pager') !== [];
  }

}
