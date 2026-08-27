<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests copying module-shipped components into a theme.
 *
 * This writes real files, so it targets a disposable fixture theme shipped
 * alongside the test (tests/themes/na_eject_target) and removes what it wrote
 * in tearDown. Pointing it at a real theme would leave components behind in
 * someone's checkout.
 */
#[Group('neo_alchemist')]
class ThemeComponentInstallerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'neo_settings',
    'neo_alchemist',
    'neo_alchemist_test',
  ];

  /**
   * The theme the fixture component is copied into.
   */
  const THEME = 'na_eject_target';

  /**
   * The fixture component's plugin id, and a claim's shipped default.
   */
  const COMPONENT = 'neo_alchemist_test:na_ejectable';

  /**
   * The fixture settings object a claim is pointed at.
   */
  const SETTINGS = 'neo_alchemist_test.settings';

  /**
   * The key in that settings object holding a component plugin id.
   */
  const SETTINGS_KEY = 'component';

  /**
   * The absolute path the test writes to.
   */
  protected string $target;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['neo_alchemist_test']);
    $this->container->get('theme_installer')->install([self::THEME]);
    $this->target = $this->container->getParameter('app.root') . '/'
      . $this->container->get('extension.list.theme')->getPath(self::THEME)
      . '/components';
    $this->removeTarget();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeTarget();
    parent::tearDown();
  }

  /**
   * Deletes anything the test wrote into the fixture theme.
   */
  protected function removeTarget(): void {
    if (isset($this->target) && is_dir($this->target)) {
      $this->container->get('file_system')->deleteRecursive($this->target);
    }
  }

  /**
   * Gets the installer.
   */
  protected function installer() {
    return $this->container->get('neo_alchemist.theme_component_installer');
  }

  /**
   * Gets the fixture settings object.
   */
  protected function settings() {
    return $this->config(self::SETTINGS);
  }

  /**
   * Tests which components are considered installable.
   *
   * The two halves of the filter both matter: a component without the flag
   * must not be dragged into every theme, and a theme's own component must not
   * be copied back over itself.
   */
  public function testOnlyFlaggedModuleComponentsAreInstallable(): void {
    $ids = array_keys($this->installer()->getInstallableDefinitions());
    $this->assertContains(self::COMPONENT, $ids);

    $definitions = $this->container->get('plugin.manager.sdc')->getDefinitions();
    foreach ($ids as $id) {
      $this->assertNotEmpty($definitions[$id]['neo_install'] ?? NULL);
      $this->assertSame('neo_alchemist_test', explode(':', $id)[0], 'Only the fixture module ships an installable component here.');
    }
    // A plain neo component in the same test module is not swept up.
    $this->assertArrayHasKey('neo_alchemist_test:na_leaf', $definitions);
    $this->assertNotContains('neo_alchemist_test:na_leaf', $ids);
  }

  /**
   * Tests the copy, the neo flip, and recursion into subdirectories.
   */
  public function testInstallCopiesAndEnablesTheComponent(): void {
    $this->assertSame('installed', $this->installer()->install(self::COMPONENT, self::THEME));

    $dir = $this->target . '/na_ejectable';
    $this->assertFileExists($dir . '/na_ejectable.twig');
    // Recursive: a flat glob would have silently skipped this.
    $this->assertFileExists($dir . '/nested/probe.txt');

    $yaml = (string) file_get_contents($dir . '/na_ejectable.component.yml');
    $this->assertStringContainsString('neo: true', $yaml, 'The theme copy is a real Alchemist component.');
    $this->assertStringNotContainsString('neo: false', $yaml);

    // The source is untouched — it stays out of the picker.
    $source = $this->container->get('plugin.manager.sdc')->getDefinition(self::COMPONENT);
    $this->assertStringContainsString('neo: false', (string) file_get_contents($source['path'] . '/na_ejectable.component.yml'));
  }

  /**
   * Tests that an existing copy is the site's and is never clobbered.
   */
  public function testExistingCopyIsNotOverwritten(): void {
    $this->installer()->install(self::COMPONENT, self::THEME);
    $twig = $this->target . '/na_ejectable/na_ejectable.twig';
    file_put_contents($twig, '<p>site edit</p>');

    $this->assertSame('exists', $this->installer()->install(self::COMPONENT, self::THEME));
    $this->assertSame('<p>site edit</p>', file_get_contents($twig), 'A second install left the edit alone.');

    $this->assertSame('installed', $this->installer()->install(self::COMPONENT, self::THEME, TRUE));
    $this->assertStringContainsString('na-ejectable', (string) file_get_contents($twig), 'force restored the module version.');
  }

  /**
   * Tests that installAll() reports every flagged component.
   */
  public function testInstallAll(): void {
    $results = $this->installer()->installAll(self::THEME);
    $this->assertSame('installed', $results[self::COMPONENT] ?? NULL);
    $this->assertSame(['installed'], array_values(array_unique($results)));
  }

  /**
   * Tests that a theme which cannot be resolved is a no-op, not an error.
   *
   * On a fresh site modules install before any theme exists, so this path runs
   * for real on every install. Throwing here would abort the install.
   */
  public function testUnresolvableThemeIsNoOp(): void {
    $this->assertNull($this->installer()->resolveTheme('does_not_exist'));
    $this->assertSame([], $this->installer()->installAll('does_not_exist'));
  }

  /**
   * Tests that an unknown component is rejected rather than silently skipped.
   */
  public function testUnknownComponentRejected(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->installer()->install('neo_alchemist_test:na_leaf', self::THEME);
  }

  /**
   * Tests that a claim repoints the shipped default at the theme copy.
   */
  public function testClaimRepointsTheShippedDefault(): void {
    $this->assertSame(self::COMPONENT, $this->settings()->get(self::SETTINGS_KEY));

    $status = $this->installer()->claimComponent(self::COMPONENT, self::SETTINGS, self::SETTINGS_KEY, self::THEME);

    $this->assertSame('claimed', $status);
    $this->assertSame(self::THEME . ':na_ejectable', $this->settings()->get(self::SETTINGS_KEY));
  }

  /**
   * Tests that a site builder's own choice is left alone.
   *
   * This is the check that makes a claim safe to run from more than one hook
   * and on every install: anything other than the shipped default is a
   * decision someone made, and a claim never reverts one.
   */
  public function testClaimKeepsAnExistingChoice(): void {
    $this->settings()->set(self::SETTINGS_KEY, 'na_eject_target:something_else')->save();

    $status = $this->installer()->claimComponent(self::COMPONENT, self::SETTINGS, self::SETTINGS_KEY, self::THEME);

    $this->assertSame('kept', $status);
    $this->assertSame('na_eject_target:something_else', $this->settings()->get(self::SETTINGS_KEY));
  }

  /**
   * Tests that a second claim reports 'kept' rather than reclaiming.
   *
   * Both hooks a consumer claims from can fire on the same request, so the
   * second pass over an already-claimed key is the normal case, not an edge.
   */
  public function testSecondClaimIsKept(): void {
    $this->assertSame('claimed', $this->installer()->claimComponent(self::COMPONENT, self::SETTINGS, self::SETTINGS_KEY, self::THEME));

    $status = $this->installer()->claimComponent(self::COMPONENT, self::SETTINGS, self::SETTINGS_KEY, self::THEME);

    $this->assertSame('kept', $status);
    $this->assertSame(self::THEME . ':na_ejectable', $this->settings()->get(self::SETTINGS_KEY));
  }

  /**
   * Tests that a claim with no resolvable theme writes nothing.
   *
   * Modules install before any theme on a fresh site, so this path runs for
   * real on every install; the consumer's other hook claims once a theme
   * arrives.
   */
  public function testClaimWithNoThemeIsUnavailable(): void {
    $status = $this->installer()->claimComponent(self::COMPONENT, self::SETTINGS, self::SETTINGS_KEY, 'does_not_exist');

    $this->assertSame('unavailable', $status);
    $this->assertSame(self::COMPONENT, $this->settings()->get(self::SETTINGS_KEY));
  }

  /**
   * Tests that a component the theme has no copy of is never claimed.
   *
   * The na_leaf fixture does not declare neo_install, so the sweep does not
   * write it into the theme and there is nothing for the key to point at.
   * Writing the id anyway would name a component that does not exist.
   */
  public function testClaimWithNoThemeCopyIsUnavailable(): void {
    $default = 'neo_alchemist_test:na_leaf';
    $this->settings()->set(self::SETTINGS_KEY, $default)->save();

    $status = $this->installer()->claimComponent($default, self::SETTINGS, self::SETTINGS_KEY, self::THEME);

    $this->assertSame('unavailable', $status);
    $this->assertSame($default, $this->settings()->get(self::SETTINGS_KEY));
  }

}
