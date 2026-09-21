<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Demande d'envoi d'un push web. Traité en asynchrone par Messenger :
 * les appels HTTPS vers les serveurs de push (Google, Mozilla…) ne
 * doivent jamais bloquer une réponse HTTP.
 */
final readonly class SendPushNotification
{
    public function __construct(
        public string $title,
        public string $body,
        public ?string $userId = null,
        public ?string $url = null,
        /** URL que le service worker appelle à réception — l'accusé qui dispense de l'e-mail de secours */
        public ?string $ackUrl = null,
    ) {}
}
