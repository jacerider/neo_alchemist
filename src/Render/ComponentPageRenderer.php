<?php

namespace Drupal\neo_alchemist\Render;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\AttachmentsResponseProcessorInterface;
use Drupal\Core\Render\BareHtmlPageRenderer;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Default bare HTML page renderer.
 */
class ComponentPageRenderer extends BareHtmlPageRenderer {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The theme manager.
   *
   * @var \Drupal\Core\Theme\ThemeManagerInterface
   */
  protected $themeManager;

  /**
   * Constructs a new BareHtmlPageRenderer.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Render\AttachmentsResponseProcessorInterface $html_response_attachments_processor
   *   The HTML response attachments processor service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Theme\ThemeManagerInterface $theme_manager
   *   The theme manager.
   */
  public function __construct(
    RendererInterface $renderer,
    AttachmentsResponseProcessorInterface $html_response_attachments_processor,
    ModuleHandlerInterface $module_handler,
    ThemeManagerInterface $theme_manager
  ) {
    parent::__construct($renderer, $html_response_attachments_processor);
    $this->moduleHandler = $module_handler;
    $this->themeManager = $theme_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function renderBarePage(array $content, $title, $page_theme_property, array $page_additions = []) {

    // Allow hooks to add attachments to $page['#attached'].
    $this->renderer->executeInRenderContext(new RenderContext(), function () use (&$content) {
      $this->invokePageAttachmentHooks($content);
    });

    $attributes = [
      'class' => [
        str_replace('_', '-', $page_theme_property),
      ],
    ];
    $html = [
      '#type' => 'html',
      '#attributes' => $attributes,
      'page' => [
        '#type' => 'page',
        '#theme' => $page_theme_property,
        '#title' => $title,
        'content' => $content,
      ] + $page_additions,
    ];

    // For backwards compatibility.
    // @todo In Drupal 9, add a $show_messages function parameter.
    if (!isset($page_additions['#show_messages']) || $page_additions['#show_messages'] === TRUE) {
      $html['page']['highlighted'] = ['#type' => 'status_messages'];
    }

    $htmlHead = [];
    // Since we do not build top or bottom, allow neo build to inject
    // attachments. This brings in CSS color variables.
    neo_build_page_top($htmlHead);
    foreach ($htmlHead as $key => $value) {
      $html['page']['#attached']['html_head'][] = [$value, $key];
    }

    $this->renderer->renderRoot($html);

    $response = new HtmlResponse();
    $response->setContent($html);
    // Process attachments, because this does not go via the regular render
    // pipeline, but will be sent directly.
    $response = $this->htmlResponseAttachmentsProcessor->processAttachments($response);
    return $response;
  }

  /**
   * Invokes the page attachment hooks.
   *
   * @param array &$page
   *   A #type 'page' render array, for which the page attachment hooks will be
   *   invoked and to which the results will be added.
   *
   * @throws \LogicException
   *
   * @internal
   *
   * @see hook_page_attachments()
   * @see hook_page_attachments_alter()
   */
  public function invokePageAttachmentHooks(array &$page) {
    // Modules can add attachments.
    $attachments = [];
    $this->moduleHandler->invokeAllWith(
      'page_attachments',
      function (callable $hook, string $module) use (&$attachments) {
        $hook($attachments);
      }
    );
    if (array_diff(array_keys($attachments), ['#attached', '#cache']) !== []) {
      throw new \LogicException('Only #attached and #cache may be set in hook_page_attachments().');
    }

    // Modules and themes can alter page attachments.
    $this->moduleHandler->alter('page_attachments', $attachments);
    $this->themeManager->alter('page_attachments', $attachments);
    if (array_diff(array_keys($attachments), ['#attached', '#cache']) !== []) {
      throw new \LogicException('Only #attached and #cache may be set in hook_page_attachments_alter().');
    }

    // Merge the attachments onto the $page render array.
    $page = $this->renderer->mergeBubbleableMetadata($page, $attachments);
  }

}
