<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Un lot d'alertes billetterie à envoyer (même utilisateur, même type, même
 * canal) — les échéances portent le `pushBatch` correspondant. Produit par
 * app:notifications:dispatch, traité en asynchrone.
 */
final readonly class SendWatchAlert
{
    public function __construct(public string $batch) {}
}
