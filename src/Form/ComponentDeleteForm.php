<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\Form;

use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\neo_alchemist\ComponentUsage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Delete form that says what deleting a component will break.
 *
 * Core's EntityDeleteForm lists *config* dependents only — deliberately, since
 * a config entity's content dependents are soft. For a component that is the
 * one thing worth knowing: config hosts repair themselves through
 * onDependencyRemoval(), while every content entity that placed the component
 * keeps a string pointing at nothing. Those pages then render without it, with
 * no error and nothing in the logs.
 *
 * So the stock form is accurate and still misleading: it can show no
 * dependents at all for a component placed on fifty pages.
 *
 * @see \Drupal\neo_alchemist\DanglingComponentData
 */
class ComponentDeleteForm extends EntityDeleteForm {

  /**
   * The component usage tracker.
   */
  protected ComponentUsage $componentUsage;

  /**
   * The renderer.
   */
  protected RendererInterface $renderer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->componentUsage = $container->get('neo_alchemist.component_usage');
    $instance->renderer = $container->get('renderer');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $componentId = (string) $this->entity->id();
    // Usage resolution renders host labels, so it needs a context; a form
    // build has one, but the isolation keeps any bubbled metadata out of the
    // form array.
    $usages = $this->renderer->executeInRenderContext(
      new RenderContext(),
      fn (): array => $this->componentUsage->getUsages($componentId)
    );
    $places = array_merge($usages['content'] ?? [], $usages['default'] ?? [], $usages['block'] ?? []);
    if (!$places) {
      return $form;
    }

    $items = [];
    foreach ($places as $place) {
      $label = (string) ($place['label'] ?? '');
      $url = $place['url'] ?? NULL;
      $items[] = $url instanceof Url
        ? ['#type' => 'link', '#title' => $label, '#url' => $url]
        : ['#markup' => $label];
    }

    $form['neo_alchemist_usage'] = [
      '#theme' => 'item_list',
      '#title' => $this->formatPlural(
        count($places),
        'This component is placed in 1 place. Deleting it will leave that placement rendering nothing — silently, with no error on the page.',
        'This component is placed in @count places. Deleting it will leave those placements rendering nothing — silently, with no error on the page.',
      ),
      '#items' => $items,
      '#weight' => -10,
      '#prefix' => '<div class="messages messages--warning">',
      '#suffix' => '</div>',
    ];
    $form['description']['#weight'] = -9;

    return $form;
  }

}
