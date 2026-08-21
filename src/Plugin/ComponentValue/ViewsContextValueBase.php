<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProducerInterface;
use Drupal\views\ViewExecutable;

/**
 * Shared machinery for value providers that read a bound view.
 *
 * The `views` value provider executes its view and registers it as a prop
 * shape context (ViewsValue::getView()); everything else views-backed reads
 * it back from there. This base is that read side, and nothing more: the
 * context select for a configuration form, the context lookup at render, and
 * the query-string cache context every such value needs.
 *
 * Deliberately service-free. None of these members touch anything but the
 * shape, so a subclass that needs no services of its own — a plain counter,
 * say — is a handful of lines with no constructor and no container plumbing.
 * Subclasses needing services add ContainerFactoryPluginInterface themselves;
 * ViewsExposedFilterValueBase is the example.
 *
 * Everything resolves at the MODIFY stage of the value pipeline, never at
 * default time: defaults run at shape init inside loadPropShapes(), where the
 * views provider has not executed its view yet and where forcing a shape
 * build recurses fatally. Hence every context read here passes $build FALSE.
 *
 * The views SLOT plugins carry a near-identical copy of the context select
 * (ViewsSlotBase::getOptions() and its configurationForm()). They cannot share
 * this base — a slot extends ComponentSlotPluginBase, a different plugin type
 * with a different constructor — so the duplication there is structural, not
 * an oversight.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsValue
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsExposedFilterValueBase
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\ViewsSummaryValue
 */
abstract class ViewsContextValueBase extends ComponentValuePluginBase implements ComponentValueProducerInterface {

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return FALSE;
  }

  /**
   * Ajax callback.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $parents = array_slice($trigger['#array_parents'], 0, -1);
    return NestedArray::getValue($form, $parents);
  }

  /**
   * Get the available options for the views context.
   *
   * At form time only the context KEYS exist — the executed view value is
   * registered at render — which is all the select needs.
   *
   * @return array
   *   Context shape titles keyed by context id.
   */
  protected function getContextOptions(): array {
    $options = [];
    if ($viewsContexts = $this->shape->getComponent()->getPropShapeContexts('views')) {
      foreach ($viewsContexts as $context => $contextInfo) {
        $options[$context] = $contextInfo['shape']->getTitle();
      }
    }
    return $options;
  }

  /**
   * Builds the views-context select for a configuration form.
   *
   * The caller must have set $form['#id'] before calling this: it is the AJAX
   * rewrap target, and it is per-plugin-instance, so it cannot be derived here.
   *
   * @param array $form
   *   The form, with '#parents' and '#id' set.
   *
   * @return array
   *   The form with the context element (and AJAX rewrap wiring) added.
   */
  protected function buildContextFormElement(array $form): array {
    if ($options = $this->getContextOptions()) {
      $form['context'] = [
        '#type' => 'select',
        '#title' => $this->t('Views Context'),
        '#description' => $this->t('The context key provided by a value plugin that contains the views object.'),
        '#options' => $options,
        '#empty_option' => $this->t('- Select -'),
        '#default_value' => $this->configuration['context'],
        '#required' => TRUE,
        '#ajax' => [
          'callback' => [static::class, 'refreshAjax'],
          'wrapper' => $form['#id'],
        ],
      ];
    }
    return $form;
  }

  /**
   * Returns the executed view registered under a context, if resolved.
   *
   * $build MUST stay FALSE: forcing a shape build from inside the value
   * pipeline re-runs every shape's init — including the instance currently
   * executing — and recurses until memory runs out.
   *
   * @param string $context
   *   The views context key.
   *
   * @return \Drupal\views\ViewExecutable|null
   *   The executed view, or NULL when the context is not (yet) resolved.
   */
  protected function getContextView(string $context): ?ViewExecutable {
    $viewsContexts = $this->shape->getComponent()->getPropShapeContexts('views', FALSE);
    $view = $viewsContexts[$context]['value'] ?? NULL;
    return $view instanceof ViewExecutable ? $view : NULL;
  }

  /**
   * Registers that this value varies by the query string.
   */
  protected function addQueryCacheability(): void {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(['url.query_args']);
    $this->shape->addCacheableDependency($cacheability);
  }

  /**
   * Builds the "Available in twig" reference for a configuration form.
   *
   * These props are only useful to whoever writes the template, and what a
   * template can reach is not visible from the prop's schema — the value
   * arrives as a helper object whose methods carry the wiring that has to be
   * exactly right. So each provider documents its own surface here, next to
   * the setting that produces it, keyed on the prop's actual name so the
   * snippets are copy-pasteable as they stand.
   *
   * @param array $fields
   *   Data keys, as suffix => description. Rendered as `<prop>.<suffix>`.
   * @param array $methods
   *   Helper methods, as call => description. Rendered as `<prop>.<call>`.
   *   Empty for a provider whose value is plain data.
   * @param array $examples
   *   Twig lines to show verbatim under the table.
   * @param string|\Drupal\Core\StringTranslation\TranslatableMarkup|null $note
   *   Optional closing paragraph, already safe for output.
   *
   * @return array
   *   A details element, weighted below the plugin's own settings.
   */
  protected function buildTwigReferenceElement(array $fields, array $methods, array $examples, mixed $note = NULL): array {
    $name = $this->shape->getName();

    $rows = [];
    foreach ($fields + $methods as $key => $description) {
      $rows[] = [
        ['data' => ['#markup' => '<code>' . $name . '.' . $key . '</code>']],
        ['data' => $description],
      ];
    }

    $element = [
      '#type' => 'details',
      '#title' => $this->t('Available in twig'),
      '#weight' => 10,
      '#neo_size' => 'sm',
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Variable'), $this->t('Value')],
        '#rows' => $rows,
      ],
    ];
    if ($examples) {
      $element['examples'] = [
        '#type' => 'html_tag',
        '#tag' => 'pre',
        // Escaped, not raw: html_tag's #value is rendered as markup, so an
        // unescaped snippet would inject its own <form>/<input>/<button> into
        // the settings form rather than showing the twig to copy.
        '#value' => Html::escape(implode("\n", $examples)),
        '#prefix' => '<p>' . $this->t('For example:') . '</p>',
      ];
    }
    if ($note !== NULL) {
      $element['note'] = ['#markup' => '<p>' . $note . '</p>'];
    }

    return $element;
  }

  /**
   * The standard note about an unbound instance.
   *
   * Every provider here returns nothing when it cannot resolve a view, and an
   * empty value is dropped from the props entirely rather than passed through
   * empty — so the template variable is UNDEFINED, and `|default()` is not the
   * right guard for it.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The note.
   */
  protected function unboundNote() {
    return $this->t('With no view bound — an unconfigured instance, or the editor preview — this prop resolves to nothing and <code>@name</code> is undefined rather than empty. Guard a template against that with <code>??</code>, not <code>|default()</code>.', [
      '@name' => $this->shape->getName(),
    ]);
  }

}
