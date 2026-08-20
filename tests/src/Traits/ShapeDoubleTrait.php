<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Traits;

use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Doubles a shape by the roles a test actually asks it about.
 *
 * The shape's fourteen roles exist so a caller can hold ten signatures rather
 * than a hundred, and a test double is where that pays: stub a role and a name
 * that is not on it cannot be configured at all, where on the union it is
 * accepted and quietly does nothing.
 *
 * One seam stops a role double being passed straight in.
 * ComponentValuePluginBase types its shape as ComponentShapePluginInterface,
 * so every value provider's constructor demands the whole union — and
 * narrowing that handle belongs to the componentvalue-pipeline spec, not this
 * one. A double handed to a provider therefore has to implement all hundred
 * methods whatever else is true of it.
 *
 * So the two concerns are separated. The test configures a role double, which
 * is narrow and rejects anything off it; ::shapeDouble() returns a union-typed
 * double that forwards exactly the declared roles' methods to it. The union
 * double is plumbing, and nothing is ever stubbed on it directly.
 *
 * @code
 * $context = $this->shapeRole(ComponentShapeContextInterface::class);
 * $context->method('getScope')->willReturn('field');
 *
 * new EntityValue('entity', [], $this->shapeDouble([$context]), [], $matcher);
 * @endcode
 *
 * Methods outside the declared roles keep PHPUnit's default return, as they
 * did before: this narrows what a test can *say*, which is where the ticket's
 * silent NULL came from, and does not turn every unstubbed call into an error.
 *
 * @see \Drupal\Tests\neo_alchemist\Unit\ShapeDoubleTest
 * @see \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface
 */
trait ShapeDoubleTrait {

  /**
   * The interface each role double was created for, by object id.
   *
   * Recorded rather than recovered by reflection: a double implements its
   * role's parents too, so which one the test meant is not derivable from the
   * object afterwards.
   *
   * @var string[]
   */
  private array $shapeRoleInterfaces = [];

  /**
   * The object ids of the role doubles that reached a shape double.
   *
   * @var array<int, true>
   */
  private array $shapeRolesDeclared = [];

  /**
   * Creates a narrow double for one shape role.
   *
   * @param string $interface
   *   The role interface, or a standalone capability such as
   *   ComponentShapeMediaPluginInterface.
   *
   * @return \PHPUnit\Framework\MockObject\MockObject
   *   The role double, to configure and then declare to ::shapeDouble().
   */
  protected function shapeRole(string $interface): MockObject {
    $role = $this->createMock($interface);
    $this->shapeRoleInterfaces[spl_object_id($role)] = $interface;
    return $role;
  }

  /**
   * Fails when a role was made and then never declared to a shape double.
   *
   * The mistake this trait exists to prevent, in its last remaining form. A
   * role stubbed and then left out of the ::shapeDouble() call — one dropped
   * element in a helper that assembles several — is inert: the code under test
   * asks the shape, the shape forwards to nobody, and PHPUnit's default comes
   * back. Silently, which is the failure the ticket is about.
   *
   * The rule is "declare every role", not "declare every role you stubbed",
   * because PHPUnit cannot be asked the second question — `hasMatchers()`
   * reports expectations and ignores plain stubs, which is most of them. The
   * blunter rule costs nothing: a role exists to be declared, so holding one
   * and not declaring it says nothing a test needed to say.
   */
  #[After]
  protected function assertEveryShapeRoleWasDeclared(): void {
    $stranded = array_values(array_diff_key($this->shapeRoleInterfaces, $this->shapeRolesDeclared));

    if ($stranded !== []) {
      $this->fail(sprintf(
        "These roles never reached a ::shapeDouble() call, so nothing they were told can have reached the code under test:\n- %s",
        implode("\n- ", $stranded),
      ));
    }
  }

  /**
   * Builds a shape double answering for the roles it is given.
   *
   * @param \PHPUnit\Framework\MockObject\MockObject[] $roles
   *   Role doubles from ::shapeRole(). Their methods are forwarded to them.
   * @param string[] $capabilities
   *   Interfaces the double must satisfy beyond the union, for the providers
   *   that check with instanceof before doing anything. One that already
   *   extends the union replaces it rather than joining it, since PHPUnit
   *   refuses an intersection whose members share a method.
   *
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The double, typed as the union so a value provider will accept it.
   */
  protected function shapeDouble(array $roles = [], array $capabilities = []): ComponentShapePluginInterface&MockObject {
    $shape = $this->buildShapeDouble($capabilities);

    $forwarded = [];
    foreach ($roles as $role) {
      $interface = $this->shapeRoleInterfaces[spl_object_id($role)] ?? throw new \LogicException(
        'Declare role doubles with ::shapeRole() rather than ::createMock(), so the double knows which interface to forward.'
      );
      $this->shapeRolesDeclared[spl_object_id($role)] = TRUE;
      foreach ($this->forwardableMethods($interface) as $method) {
        if (isset($forwarded[$method])) {
          throw new \LogicException(sprintf(
            'Two declared roles both answer %s(). Declare one of them, or the double would forward to whichever was listed first.',
            $method,
          ));
        }
        $forwarded[$method] = TRUE;
        $shape->method($method)->willReturnCallback(
          static fn (mixed ...$arguments): mixed => $role->{$method}(...$arguments),
        );
      }
    }

    return $shape;
  }

  /**
   * Builds a shape double that fails if anything is asked of it.
   *
   * For the providers that never look at their shape and only need one to
   * satisfy a constructor. Saying so is worth more than a hundred-method
   * double sitting there implying the opposite, and it fails the day the
   * provider starts asking.
   *
   * Every method throws rather than carrying a never() expectation, so the
   * failure lands on the call with the method name in it instead of surfacing
   * at teardown.
   *
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The double.
   */
  protected function unusedShape(): ComponentShapePluginInterface&MockObject {
    $shape = $this->buildShapeDouble();
    foreach (get_class_methods(ComponentShapePluginInterface::class) as $method) {
      $shape->method($method)->willThrowException(new \LogicException(sprintf(
        '%s() was asked of a shape double declared unused. Declare the role that answers it with ::shapeRole().',
        $method,
      )));
    }
    return $shape;
  }

  /**
   * Creates the union-typed double the roles are forwarded from.
   *
   * @param string[] $capabilities
   *   Extra interfaces the double must satisfy.
   *
   * @return \Drupal\neo_alchemist\Shape\ComponentShapePluginInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The double.
   */
  private function buildShapeDouble(array $capabilities = []): ComponentShapePluginInterface&MockObject {
    $types = [];
    foreach ([ComponentShapePluginInterface::class, ...$capabilities] as $candidate) {
      foreach ($types as $delta => $held) {
        // A candidate that extends one already held supersedes it; one already
        // covered by a held type adds nothing.
        if (is_a($candidate, $held, TRUE)) {
          unset($types[$delta]);
        }
        elseif (is_a($held, $candidate, TRUE)) {
          continue 2;
        }
      }
      $types[] = $candidate;
    }
    $types = array_values($types);

    $shape = count($types) === 1
      ? $this->createMock($types[0])
      : $this->createMockForIntersectionOfInterfaces($types);
    assert($shape instanceof ComponentShapePluginInterface);
    return $shape;
  }

  /**
   * The methods a declared interface forwards: the ones it declares itself.
   *
   * Inherited methods are left to whoever declared them. That is what lets a
   * role be declared without dragging in the identity every role extends —
   * forwarding id() from whichever role happened to be listed first would put
   * it on a double the test never configured — and it is also what makes a
   * capability declarable: ComponentShapeChildrenMatchPluginInterface extends
   * the whole union, and only its own six methods are its to answer.
   *
   * @param string $interface
   *   The declared interface.
   *
   * @return string[]
   *   The method names.
   */
  private function forwardableMethods(string $interface): array {
    $names = [];
    foreach ((new \ReflectionClass($interface))->getMethods() as $method) {
      if ($method->getDeclaringClass()->getName() === $interface) {
        $names[] = $method->getName();
      }
    }
    return $names;
  }

}
