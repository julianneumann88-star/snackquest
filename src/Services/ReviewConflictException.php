<?php
declare(strict_types=1);

namespace SnackQuest\Services;

/**
 * Raised when an offline edit is based on an older server revision.
 *
 * The client must keep the local draft and ask the user to review it instead of
 * silently overwriting the newer server state.
 */
final class ReviewConflictException extends \RuntimeException
{
    public function __construct(
        public readonly int $serverUpdatedAt,
        string $message = 'Diese Bewertung wurde inzwischen auf einem anderen Gerät geändert.'
    ) {
        parent::__construct($message);
    }
}
