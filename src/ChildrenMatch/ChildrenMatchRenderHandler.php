<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\ChildrenMatch;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\neo_alchemist\ComponentPropRenderable;
use Drupal\neo_alchemist\Match\FieldFormatterTrait;
use Drupal\neo_alchemist\Match\MatcherField;

/**
 * Handles `_render`: run a field through a formatter, keep the render array.
 *
 * The published decision is the one threaded in on the field — resolved once at
 * the provider root and handed down — so a chained render key that passes over
 * an intermediate entity drops that entity when it is unpublished and the flag
 * is set, exactly as a plain field match does. It is not re-derived from the
 * child's settings, which never carry `shape_published`.
 */
final class ChildrenMatchRenderHandler extends ChildrenMatchHandlerBase {

  use FieldFormatterTrait;

  /**
   * Constructs a ChildrenMatchRenderHandler.
   *
   * @param \Drupal\neo_alchemist\Match\MatcherField $matcherField
   *   The field matcher: resolves and reads a field for the render.
   * @param \Drupal\Core\Field\FormatterPluginManager $fieldFormatterManager
   *   The formatter manager, for the formatter form and options.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler, for formatter third-party settings forms.
   */
  public function __construct(
    protected MatcherField $matcherField,
    FormatterPluginManager $fieldFormatterManager,
    ModuleHandlerInterface $moduleHandler,
  ) {
    $this->fieldFormatterManager = $fieldFormatterManager;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'render';
  }

  /**
   * {@inheritdoc}
   */
  public function addOptions(array &$options, ChildrenMatchFormContext $context): void {
    if (in_array($context->shape->getRef(), ['markup', 'string'])) {
      $options['- Shape -']['_render'] = $this->t('Render with field formatter');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array $configuration, ChildrenMatchFormContext $context): ?array {
    $shape = $context->shape;
    $scope = $context->scope;
    $ajax = $context->ajax;
    $renderFieldId = $configuration['render_field'] ?? NULL;
    $form['render_field'] = [
      '#type' => 'neo_field_select',
      '#title' => $this->t('Field to render'),
      '#required' => TRUE,
      '#component' => $shape->getComponent()->id(),
      '#prop' => $shape->getRootShape()->getName(),
      '#shape' => $shape->id(),
      '#all' => TRUE,
      '#entity_type' => $scope->entityTypeId,
      '#bundle' => $scope->bundle,
      '#empty_option' => $this->t('- Select -'),
      '#default_value' => $renderFieldId,
      '#ajax' => $ajax,
    ];
    if ($renderFieldId) {
      $renderField = $this->matcherField->getFieldDefinition($shape, $renderFieldId, $scope->entityTypeId, $scope->bundle, NULL, TRUE);
      if ($renderField) {
        $form['render_field_format'] = [
          '#type' => 'fieldset',
          '#title' => $this->t('Formatter'),
        ];
        $renderFieldFormatConfiguration = $configuration['render_field_format'] ?? [];
        $form['render_field_format'] = $this->formatterConfigurationForm($form['render_field_format'], $context->formState, $renderField, $renderFieldFormatConfiguration, $ajax);
      }
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function fetch(ChildrenMatchField $field, ChildrenMatchMapper $mapper, ChildrenMatchSourceInterface $source): mixed {
    if (!empty($field->settings['render_field'])) {
      $item = $this->matcherField->getEntityField($field->entity, $field->settings['render_field'], $field->published, $field->shape->getCacheableMetadata());
      if ($item && !$item->isEmpty() && !empty($field->settings['render_field_format']['field_plugin'])) {
        $build = $item->view([
          'type' => $field->settings['render_field_format']['field_plugin'],
          'label' => $field->settings['render_field_format']['field_label'] ?? 'hidden',
          'settings' => $field->settings['render_field_format']['field_settings'] ?? [],
        ]);
        $cacheableMetadata = CacheableMetadata::createFromRenderArray($build);
        $field->shape->addCacheableDependency($cacheableMetadata);
        return ComponentPropRenderable::create($build);
      }
    }
    return NULL;
  }

}
