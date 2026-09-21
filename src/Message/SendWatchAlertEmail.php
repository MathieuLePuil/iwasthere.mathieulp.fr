<?php

declare(strict_types=1);

namespace App\Message;

/**
 * L'e-mail d'une alerte billetterie : soit le canal choisi, soit le secours
 * du push — différé de 5 minutes, et abandonné si le service worker a accusé
 * réception entre-temps.
 */
final readonly class SendWatchAlertEmail
{
    public function __construct(
        public string $batch,
        public bool $fallback,
    ) {}
}
