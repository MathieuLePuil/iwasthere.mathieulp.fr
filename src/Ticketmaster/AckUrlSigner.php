<?php

declare(strict_types=1);

namespace App\Ticketmaster;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * L'URL d'accusé de réception d'un push d'alerte billetterie.
 *
 * Le service worker l'appelle en POST à réception, hors de toute page : pas
 * de jeton CSRF, pas forcément de session. Ce qui l'authentifie, c'est la
 * signature HMAC du lot dans l'URL — impossible à forger sans le secret de
 * l'application, et inutile à rejouer (l'accusé est idempotent).
 */
final class AckUrlSigner
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {}

    public function url(Uuid $batch): string
    {
        return $this->urls->generate(
            'app_push_ack',
            ['batch' => (string) $batch, 'sig' => $this->sign($batch)],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    public function sign(Uuid $batch): string
    {
        return substr(hash_hmac('sha256', 'push-ack:' . $batch, $this->secret), 0, 40);
    }

    public function isValid(Uuid $batch, string $signature): bool
    {
        return hash_equals($this->sign($batch), $signature);
    }
}
