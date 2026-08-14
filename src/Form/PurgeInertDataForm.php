<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\neo_alchemist\Entity\ComponentFieldConfig;
use Drupal\neo_alchemist\InertComponentData;
use Drupal\neo_alchemist\Plugin\Field\FieldType\ComponentTreeItem;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Deletes the layout data a locked field stores but never renders.
 *
 * Not an EntityConfirmFormBase like the reset/revert/publish forms: those act
 * on a real host entity, but in field-config scope the item's entity is a
 * throwaway built by ComponentFieldConfig::getFieldItem(), so there is nothing
 * to hang an entity form on. The field config is the subject here.
 *
 * @internal
 */
final class PurgeInertDataForm extends ConfirmFormBase {

  /**
   * The field item, in field-config scope.
   */
  protected ?ComponentTreeItem $fieldItem = NULL;

  /**
   * The number of entities holding inert data.
   */
  protected int $count = 0;

  /**
   * Constructs a PurgeInertDataForm object.
   */
  public function __construct(
    protected InertComponentData $inertData,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_alchemist.inert_component_data'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'neo_alchemist_purge_inert_data';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ComponentTreeItem $neo_field = NULL): array {
    // Set before the parent builds, which calls getQuestion()/getDescription().
    $this->fieldItem = $neo_field;
    $this->count = $this->countInertEntities();
    $form = parent::buildForm($form, $form_state);
    if (!$this->count) {
      unset($form['actions']['submit']);
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    if (!$this->count) {
      return $this->t('Nothing to purge');
    }
    return $this->formatPlural(
      $this->count,
      'Delete the stored layout of 1 entity?',
      'Delete the stored layouts of @count entities?',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    if (!$this->count) {
      return $this->t('No entity is storing layout data for this field.');
    }
    return $this->t('This field no longer allows customization, so these layouts are not rendered — the default layout is. They are still stored, and turning customization back on would render them again. Purging deletes them for good; nothing else changes.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Purge');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    $field = $this->getFieldConfig();
    return $field ? $field->toUrl() : Url::fromRoute('<front>');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $field = $this->getFieldConfig();
    $form_state->setRedirectUrl($this->getCancelUrl());
    if (!$field) {
      return;
    }
    $purged = $this->inertData->purge(
      $field->getTargetEntityTypeId(),
      $field->getTargetBundle(),
      $field->getName(),
    );
    if (!$purged) {
      $this->messenger()->addStatus($this->t('There was no stored layout data to purge.'));
      return;
    }
    $this->messenger()->addStatus($this->formatPlural(
      $purged,
      'Purged the stored layout of 1 entity.',
      'Purged the stored layouts of @count entities.',
    ));
  }

  /**
   * Counts the entities holding inert data for this field.
   */
  protected function countInertEntities(): int {
    $field = $this->getFieldConfig();
    if (!$field) {
      return 0;
    }
    return $this->inertData->countFor(
      $field->getTargetEntityTypeId(),
      $field->getTargetBundle(),
      $field->getName(),
    );
  }

  /**
   * Gets the field config this form acts on.
   */
  protected function getFieldConfig(): ?ComponentFieldConfig {
    $definition = $this->fieldItem?->getFieldDefinition();
    return $definition instanceof ComponentFieldConfig ? $definition : NULL;
  }

}
