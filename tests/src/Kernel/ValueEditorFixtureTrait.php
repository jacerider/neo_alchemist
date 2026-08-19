<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\entity_test\Entity\EntityTestBundle;
use Drupal\user\Entity\User;

/**
 * Setup shared by the tests that build or submit a component value editor.
 *
 * Two things stand between a Kernel test and a prop form, and neither is what
 * any of these tests is about — so they are stated once here rather than
 * copied into each.
 */
trait ValueEditorFixtureTrait {

  /**
   * Stands up a host type and an account a prop form can be built for.
   *
   * A prop form reaches getEntity(), so a component with no host type falls
   * back to a node placeholder, which a Kernel test this small does not have;
   * naming `entity_test_with_bundle` on the component avoids it. And every
   * prop form is gated on update access to the component, so without a
   * privileged account the harvest is empty and nothing else asserts anything
   * — root is the cheapest way to say "permission is not under test here".
   *
   * The caller installs the `user` entity schema first; some base classes
   * already have, and installing it twice fails on the existing tables.
   */
  protected function installValueEditorHost(): void {
    $this->installEntitySchema('entity_test_with_bundle');
    EntityTestBundle::create(['id' => 'main', 'label' => 'Main'])->save();
    $root = User::create(['uid' => 1, 'name' => 'root', 'status' => 1]);
    $root->save();
    $this->container->get('current_user')->setAccount($root);
  }

  /**
   * A string prop's submission, shaped the way its widget submits one.
   *
   * A prop form is a field widget built over a synthetic field definition, so
   * what arrives is a field submission: the shape's own name repeating inside
   * its subform, holding the widget's delta array — not a bare value.
   *
   * @param string $propName
   *   The prop's name.
   * @param string $value
   *   The submitted string.
   *
   * @return array
   *   The submission, relative to the `values` element.
   */
  protected function stringSubmission(string $propName, string $value): array {
    return [$propName => [$propName => [0 => ['value' => $value]]]];
  }

}
