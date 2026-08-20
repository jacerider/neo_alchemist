<?php

namespace Drupal\neo_alchemist\Form;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;

/**
 * Component form.
 *
 * @internal
 */
class ComponentAggregateForm extends EntityConfirmFormBase {

  /**
   * The entity.
   *
   * @var \Drupal\neo_alchemist\ComponentInterface
   */
  protected $entity;

  /**
   * Gets the actual form array to be built.
   *
   * @see \Drupal\Core\Entity\EntityForm::processForm()
   * @see \Drupal\Core\Entity\EntityForm::afterBuild()
   */
  public function form(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->entity->isAggregate()
      ? $this->t('%label: Disable Aggregation', ['%label' => $this->entity->label()])
      : $this->t('%label: Enable Aggregation', ['%label' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->entity->toUrl('canonical');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->entity->isAggregate()
      ? $this->t('Disable')
      : $this->t('Enable');
  }

  /**
   * {@inheritdoc}
   *
   * The warning is not boilerplate. Switching changes the prop set, which
   * changes the generated expression, which sends Component::preSave() down its
   * rebuild branch — so all existing prop value settings are discarded either
   * way, with no merge and no undo. Without saying so, "Enable Aggregation"
   * reads like a presentational regrouping of the same settings.
   *
   * @see \Drupal\neo_alchemist\Entity\Component::preSave()
   */
  public function getDescription() {
    // The placeholder is a TranslatableMarkup, so its markup is passed through
    // rather than escaped, and ConfirmFormBase renders the result as #markup
    // (Xss::filterAdmin, which allows <strong>).
    return $this->t('Aggregation combines all props into a single prop for management purposes. It is ideal for simplifying value binding in complex components. @warning', [
      '@warning' => $this->buildWarning(),
    ]);
  }

  /**
   * Names exactly what switching will throw away.
   *
   * A generic "this discards your settings" is easy to click past, and the
   * click is usually exploratory — "let me see what aggregation does" — so the
   * warning has to be specific enough to stop someone who is about to lose real
   * work. It also stays quiet when there is nothing to lose, which is what
   * keeps it credible on the occasions it does fire.
   *
   * Each variant is one whole translatable string, markup included. Building it
   * by concatenation instead returns a plain PHP string, and a plain string in
   * an @-placeholder is escaped — the tags then render as literal text.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The warning.
   */
  private function buildWarning(): MarkupInterface {
    $lost = $this->discardedLabels();
    if (!$lost) {
      return $this->t('No value providers are configured yet, so there is little to lose.');
    }
    $list = implode(', ', $lost);
    if ($this->entity->isAggregate()) {
      return $this->t('<strong>Disabling returns every prop to its default, unconfigured state, discarding the aggregated value settings (@list). This cannot be undone — switching back will not bring them back.</strong>', [
        '@list' => $list,
      ]);
    }
    return $this->formatPlural(
      count($lost),
      '<strong>Enabling returns every prop to its default, unconfigured state — including the value provider configured on 1 prop: @list. This cannot be undone — switching back will not bring them back.</strong>',
      '<strong>Enabling returns every prop to its default, unconfigured state — including the value providers configured on @count props: @list. This cannot be undone — switching back will not bring them back.</strong>',
      ['@list' => $list],
    );
  }

  /**
   * Human labels for the settings the switch will discard.
   *
   * Enabling loses the per-prop bindings, so the props are named. Disabling
   * loses the single aggregate binding, so its providers are named — the props
   * are no help there, since the whole point is that one provider fed them all.
   *
   * @return string[]
   *   The labels, deduplicated and in schema order.
   */
  private function discardedLabels(): array {
    $labels = [];
    if ($this->entity->isAggregate()) {
      foreach ($this->configuredPlugins($this->entity->getPropShape('_aggregate')) as $instance) {
        $labels[] = (string) $instance->label();
      }
    }
    else {
      foreach ($this->entity->getPropShapes() as $shape) {
        if ($this->configuredPlugins($shape)) {
          $labels[] = (string) $shape->getTitle();
        }
      }
    }
    return array_values(array_unique($labels));
  }

  /**
   * The value plugins configured anywhere beneath a root prop shape.
   *
   * Walks the whole subtree because a binding usually lives on a CHILD — a
   * heading's title, an array item's link — and a prop whose children are bound
   * loses just as much as one bound at the root.
   *
   * Two exclusions keep this counting work rather than scaffolding: plugins the
   * shape ships as its own `default_plugins` (markup always carries
   * `formatted_text`, image/file/video carry `media`, scheme carries `widget` —
   * none of which anyone chose), and shapes whose plugins are never persisted
   * anyway, mirroring Component::setPropShapeSettings().
   *
   * The first exclusion trades a narrow false negative for credibility:
   * settings customised ON a default plugin — a text format picked for
   * `formatted_text` — are lost without being named here. Counting defaults
   * instead would name
   * `image`, `description` and `scheme` on virtually every component, and a
   * warning that fires every time is one nobody reads. The message therefore
   * says "including", not "only", and still leads with the fact that every prop
   * is reset.
   *
   * @param \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface|null $shape
   *   The root shape, or NULL when it does not resolve.
   *
   * @return \Drupal\neo_alchemist\Value\ComponentValuePluginInterface[]
   *   The configured plugin instances, keyed by instance id.
   *
   * @see \Drupal\neo_alchemist\Entity\Component::setPropShapeSettings()
   */
  private function configuredPlugins(?ComponentShapePluginInterface $shape): array {
    if (!$shape) {
      return [];
    }
    $configured = [];
    foreach ($shape->getAllShapes(TRUE) as $childShape) {
      if (!$childShape->allowConfigurablePlugins()) {
        continue;
      }
      $defaults = $childShape->getDefaultPlugins();
      foreach ($childShape->getValueCollection()->getActiveInstances() as $instanceId => $instance) {
        if (!isset($defaults[$instanceId])) {
          $configured[$instanceId] = $instance;
        }
      }
    }
    return $configured;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->entity->set('aggregate', !$this->entity->isAggregate());
    $this->entity->save();
    $this->messenger()->addStatus($this->t('The component %label has been updated.', ['%label' => $this->entity->label()]));
    $form_state->setRedirectUrl($this->entity->toUrl('canonical'));
  }

}
