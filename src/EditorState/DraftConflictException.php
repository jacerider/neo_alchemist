<?php

declare(strict_types=1);

namespace Drupal\neo_alchemist\EditorState;

/**
 * Thrown when a shared-draft write carries a version behind the stored one.
 *
 * Optimistic conflict detection: a session carries the draft version it loaded,
 * and a write whose carried version is behind the stored one is refused rather
 * than allowed to overwrite a colleague's work with a stale copy. A lock was
 * considered and rejected — it turns a rare collision into a routine
 * obstruction, and a held lock outlives the person who left for lunch — so the
 * conflict an editor used to discover at publish time is caught at edit time,
 * and the editor is offered a reload.
 *
 * The exception carries only the raw facts of the collision: the version the
 * session loaded, the version now stored, and the user id of whoever last wrote
 * the draft. Naming that user and offering a reload belongs to the form layer,
 * which has the entity, user and route context the store deliberately does not.
 */
final class DraftConflictException extends \RuntimeException {

  /**
   * Constructs the exception.
   *
   * @param int $expectedVersion
   *   The draft version the refused session loaded and carried on its write.
   * @param int $storedVersion
   *   The draft version now stored — ahead of the carried one.
   * @param int|null $lastEditorUid
   *   The user id of the draft's last editor, or NULL when unknown.
   */
  public function __construct(
    protected readonly int $expectedVersion,
    protected readonly int $storedVersion,
    protected readonly ?int $lastEditorUid,
  ) {
    parent::__construct(sprintf(
      'The shared draft changed under this session: it loaded version %d but version %d is stored.',
      $expectedVersion,
      $storedVersion,
    ));
  }

  /**
   * The draft version the refused session loaded.
   */
  public function getExpectedVersion(): int {
    return $this->expectedVersion;
  }

  /**
   * The draft version now stored, ahead of the carried one.
   */
  public function getStoredVersion(): int {
    return $this->storedVersion;
  }

  /**
   * The user id of the draft's last editor, or NULL when unknown.
   */
  public function getLastEditorUid(): ?int {
    return $this->lastEditorUid;
  }

}
