<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Form\FormState;
use Drupal\Core\Url;
use Drupal\neo_alchemist\Form\InstanceComponentPublishForm;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Save leaves the canvas for the entity's page; with no page it stays.
 *
 * Saving is how an editor finishes, so a confirmed Save sends the browser to
 * the page that shows what was saved. When there is no such page the editor
 * can see, the save stays on the canvas and toasts, as Revert does.
 *
 * @see \Drupal\neo_alchemist\Form\InstanceComponentPublishForm::savedUrl()
 * @see \Drupal\neo_alchemist\Form\StayOnCanvasTrait::leaveCanvas()
 */
#[Group('neo_alchemist')]
class SaveLeavesCanvasTest extends HybridFieldKernelTestBase {

  use UserCreationTrait;

  /**
   * Builds the publish form with its protected seams exposed.
   */
  private function publishForm(): InstanceComponentPublishForm {
    // This module's own test may extend its own internal class.
    // @phpstan-ignore classExtendsInternalClass.classExtendsInternalClass
    $form = new class($this->container->get('plugin.manager.field.widget')) extends InstanceComponentPublishForm {

      /**
       * Whether to answer as a dialog (AJAX) submit.
       */
      public bool $ajax = TRUE;

      /**
       * {@inheritdoc}
       */
      protected function isAjax() {
        return $this->ajax;
      }

      /**
       * Exposes savedUrl() for an entity.
       */
      public function urlFor($entity, ?string $state = NULL): ?Url {
        $this->entity = $entity;
        return $this->savedUrl($state);
      }

      /**
       * Leaves the canvas, then builds the dialog submit's response.
       */
      public function leave(Url $url): array {
        $form_state = new FormState();
        $this->leaveCanvas($form_state, $url, 'Saved');
        return $this->ajax
          ? $this->successfulAjaxSubmit([], $form_state)->getCommands()
          : [$form_state->getRedirect()];
      }

    };
    $form->setStringTranslation($this->container->get('string_translation'));
    $form->setMessenger($this->container->get('messenger'));
    return $form;
  }

  /**
   * An editor who can view the entity is sent to its canonical page.
   */
  public function testSaveTargetsCanonicalPage(): void {
    $this->setUpCurrentUser([], ['view test entity']);
    $entity = $this->createTestEntity();
    $url = $this->publishForm()->urlFor($entity);
    $this->assertNotNull($url, 'A viewable entity has a page to go to.');
    $this->assertSame($entity->toUrl()->toString(), $url->toString(), 'Save goes to the canonical page.');
  }

  /**
   * With no page the editor can view, the save stays on the canvas.
   */
  public function testSaveStaysWithoutViewablePage(): void {
    $this->setUpCurrentUser();
    $entity = $this->createTestEntity();
    $this->assertNull($this->publishForm()->urlFor($entity), 'An entity the editor cannot view keeps the save on the canvas.');
  }

  /**
   * The dialog submit answers with a redirect, not a canvas refresh.
   */
  public function testDialogSubmitRedirects(): void {
    $url = Url::fromUri('internal:/entity_test/1');
    $commands = $this->publishForm()->leave($url);
    $redirect = (new RedirectCommand($url->toString()))->render();
    $this->assertSame([$redirect], $commands, 'The only command sends the browser to the page.');
    $this->assertSame(['Saved'], array_map('strval', $this->container->get('messenger')->messagesByType('status')), 'The page says what happened.');
  }

  /**
   * A submit outside a dialog redirects through the form state.
   */
  public function testPageSubmitRedirects(): void {
    $form = $this->publishForm();
    $form->ajax = FALSE;
    $url = Url::fromUri('internal:/entity_test/1');
    $this->assertSame([$url], $form->leave($url), 'The form state carries the redirect.');
  }

}
