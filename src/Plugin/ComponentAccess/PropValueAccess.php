<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentAccess;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentAccess;
use Drupal\neo_alchemist\ComponentAccessPluginBase;

/**
 * Plugin implementation of the neo_component_access.
 *
 * Hides the component on the frontend unless every selected prop has a value.
 */
#[ComponentAccess(
  id: 'prop_value',
  label: new TranslatableMarkup('Prop value'),
  description: new TranslatableMarkup('Only show the component when the selected prop(s) have a value.'),
)]
final class PropValueAccess extends ComponentAccessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'props' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();

    $props = array_filter($this->configuration['props'] ?? []);
    if ($props) {
      $shapes = $this->access->getComponent()->getPropShapes();
      $labels = array_map(
        fn ($prop) => (string) ($shapes[$prop]?->getTitle() ?? $prop),
        array_values($props),
      );
      $summary[] = $this->t('Requires a value in: @props', [
        '@props' => implode(', ', $labels),
      ]);
    }

    return $summary;
  }

  /**
   * Configuration form for the access plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $options = array_map(
      fn ($shape) => $shape->getTitle(),
      $this->access->getComponent()->getPropShapes(),
    );

    $form['props'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Required props'),
      '#description' => $this->t('The component is shown on the frontend only if every selected prop has a value.'),
      '#options' => $options,
      '#default_value' => $this->configuration['props'] ?? [],
    ];

    return $form;
  }

  /**
   * Form validation for the access plugin configuration.
   */
  protected function configurationValidate(array $form, FormStateInterface $form_state): void {
    $props = array_values(array_filter($form_state->getValue('props', [])));
    $form_state->setValue('props', $props);
  }

  /**
   * {@inheritdoc}
   */
  public function bypassAdminAccess(string $op): bool {
    // Enforce the content-presence gate on the frontend even for
    // administrators, so a component with no value is hidden for everyone.
    // Backend management (update/create) still bypasses for administrators.
    return $op !== 'view';
  }

  /**
   * {@inheritdoc}
   */
  public function access(string $op, AccountInterface $account): AccessResultInterface {
    // Only gate frontend visibility; never block backend management.
    if ($op !== 'view') {
      return AccessResult::neutral();
    }

    $props = array_filter($this->configuration['props'] ?? []);
    if (!$props) {
      return AccessResult::neutral();
    }

    $component = $this->access->getComponent();
    // Resolving props runs any value providers and accumulates their
    // cacheability (entity_query *_list tags, entity_reference entity tags, …)
    // on the component. getPropValues() is keyed by prop machine name with
    // empty props already removed, so a present key means the prop has a
    // non-empty resolved value.
    $values = $component->getPropValues();
    $result = AccessResult::neutral();
    foreach ($props as $propName) {
      if (!array_key_exists($propName, $values)) {
        $result = AccessResult::forbidden(sprintf('The "%s" prop has no value.', $propName));
        break;
      }
    }

    // Attach the resolved-value cacheability so the decision is re-evaluated
    // when those dependencies change. The render call sites capture this
    // result's cacheability (see ComponentTreeHydrated / RegionShape).
    $result->addCacheableDependency($component->getCacheableMetadata());
    return $result;
  }

}
