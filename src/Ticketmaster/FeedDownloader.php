<?php

declare(strict_types=1);

namespace App\Ticketmaster;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Télécharge le flux France dans un fichier temporaire.
 *
 * L'endpoint répond 303 vers un fichier S3 horodaté ; le client suit la
 * redirection. Le corps est écrit en continu sur disque (jamais en mémoire :
 * plusieurs dizaines de Mo compressés). Avant de commencer, on vérifie
 * l'espace disponible — un disque plein produirait un gzip tronqué, que le
 * parseur refuserait, mais autant ne pas télécharger pour rien.
 */
final class FeedDownloader
{
    public const ENDPOINT = 'https://app.ticketmaster.com/discovery-feed/v2/events.json';

    /** En deçà, on ne télécharge pas : le compressé fait ~50 Mo, on garde de la marge. */
    public const MIN_FREE_BYTES = 512 * 1024 * 1024;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
    ) {}

    /**
     * @param string $target chemin du .gz à écrire (le répertoire doit exister)
     *
     * @return int octets écrits
     *
     * @throws FeedException clé absente, disque insuffisant, HTTP en échec
     */
    public function download(string $target, string $countryCode = 'FR'): int
    {
        if ($this->apiKey === '') {
            throw new FeedException('TICKETMASTER_API_KEY n\'est pas renseignée.');
        }

        self::assertDiskSpace(dirname($target));

        $response = $this->httpClient->request('GET', self::ENDPOINT, [
            'query' => ['countryCode' => $countryCode, 'apikey' => $this->apiKey],
            'timeout' => 60,
            'max_duration' => 900,
        ]);

        $handle = fopen($target, 'w');
        if ($handle === false) {
            throw new FeedException(sprintf('Impossible d\'écrire %s.', $target));
        }

        $written = 0;
        try {
            if ($response->getStatusCode() !== 200) {
                throw new FeedException(sprintf('Ticketmaster a répondu HTTP %d.', $response->getStatusCode()));
            }
            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new FeedException('Téléchargement interrompu (délai dépassé).');
                }
                $content = $chunk->getContent();
                if ($content !== '') {
                    $written += (int) fwrite($handle, $content);
                }
            }
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface $e) {
            throw new FeedException('Téléchargement en échec : ' . $e->getMessage(), 0, $e);
        } finally {
            fclose($handle);
        }

        if ($written === 0) {
            throw new FeedException('Le flux téléchargé est vide.');
        }

        return $written;
    }

    /** @throws FeedException */
    public static function assertDiskSpace(string $dir): void
    {
        $free = @disk_free_space($dir);
        if ($free !== false && $free < self::MIN_FREE_BYTES) {
            throw new FeedException(sprintf('Espace disque insuffisant dans %s : %d Mo libres, %d Mo requis.', $dir, intdiv((int) $free, 1024 * 1024), intdiv(self::MIN_FREE_BYTES, 1024 * 1024)));
        }
    }
}
