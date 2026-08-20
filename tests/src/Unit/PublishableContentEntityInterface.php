<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_alchemist\Unit;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;

/**
 * A content entity that also carries a published flag.
 *
 * The mapper's published policy tests need an entity that is both, and the two
 * core interfaces cannot be combined with an intersection double: they share
 * EntityInterface, and PHPUnit refuses to double two interfaces declaring the
 * same method. Naming the combination is the way to say it once.
 */
interface PublishableContentEntityInterface extends ContentEntityInterface, EntityPublishedInterface {

}
