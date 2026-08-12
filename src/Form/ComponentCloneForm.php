<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\ComponentInterface;

/**
 * Clone form.
 *
 * Saves a copy of an existing component. The entity handed to this form is an
 * unsaved duplicate built by
 * \Drupal\neo_alchemist\Controller\ComponentCloneController, so it already
 * carries the source's props, slots, filters and access configuration. Its
 * machine name is derived on save by Component::save(), exactly as it is for a
 * component added from the library.
 */
final class ComponentCloneForm extends ComponentForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $source = $this->getSourceComponent();

    $form['description_text'] = [
      '#type' => 'item',
      '#markup' => $this->t('A copy of %label will be created with the same props, slots, filters and access rules. The thumbnail is not copied — capture a new one from the cloned component once it exists.', [
        '%label' => $source->label(),
      ]),
      '#weight' => -20,
    ];

    // The parent form seeds a brand new component from the SDC definition. A
    // clone starts from the source's own configuration instead.
    $form['label']['#weight'] = -10;
    // Concatenated rather than run through t(): a placeholder HTML-escapes the
    // label, and this value goes into a text field, not into markup — a label
    // like "Level 2 & 3" would come back as "Level 2 &amp; 3".
    $form['label']['#default_value'] = $source->label() . ' (copy)';

    $form['description']['#default_value'] = $source->get('description');

    // Props, slots and value plugins can reference fields on the scoped entity
    // type, so a clone stays on the source's entity type — the same rule the
    // parent applies to a saved component.
    $form['target_entity_type']['#disabled'] = TRUE;
    // The bundle stays open. Retargeting a copy at a sibling bundle is the
    // common reason to clone a scoped component, and because the entity type is
    // fixed the copied configuration keeps resolving against the same field
    // definitions — only bundle-specific fields need a second look.
    if (isset($form['target_entity_bundle']['#type']) && $form['target_entity_bundle']['#type'] === 'select') {
      $form['target_entity_bundle']['#description'] = $this->t('Scope this copy to a different %label bundle if you want. Props and slots bound to fields the new bundle does not have will need to be re-pointed.', [
        '%label' => $this->entityTypeManager->getDefinition($this->entity->getTargetEntityTypeId())->getLabel(),
      ]);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    $actions['submit']['#value'] = $this->t('Clone');
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    // ComponentForm::save() applies the "special" and "entity" group defaults
    // to every new component. Those would overwrite configuration the source
    // deliberately carries (protected access, per-prop editability, the
    // component-level prop editability mode), so a clone saves the copied
    // configuration as-is.
    $result = $this->entity->save();
    $this->messenger()->addStatus($this->t('Cloned %source into new component %label.', [
      '%source' => $this->getSourceComponent()->label(),
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

  /**
   * Gets the component being cloned.
   *
   * @return \Drupal\neo_alchemist\ComponentInterface
   *   The source component.
   */
  protected function getSourceComponent(): ComponentInterface {
    return $this->getRouteMatch()->getParameter('neo_component');
  }

}
