<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Thrown when setlist.fm keeps rate-limiting us (HTTP 429) after retries.
 * Callers should treat this as transient and NOT count it against the import retry budget.
 */
class SetlistFmRateLimitException extends \RuntimeException
{
}
