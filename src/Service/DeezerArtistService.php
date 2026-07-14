<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches artist pictures from the public Deezer API and stores them locally
 * in public/uploads/artists/ (one file per artist, shared across events).
 * Local hosting keeps the ticket canvas untainted (no cross-origin image).
 */
class DeezerArtistService
{
    private const SEARCH_URL = 'https://api.deezer.com/search/artist';
    /** Re-try unknown artists after a week instead of hammering the API. */
    private const MISS_TTL = 7 * 86400;

    private string $uploadDir;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        KernelInterface $kernel,
    ) {
        $this->uploadDir = $kernel->getProjectDir() . '/public/uploads/artists';
    }

    /**
     * Sets the artist image on a music event when one can be found.
     * Never throws — a Deezer hiccup must not break event creation.
     */
    public function applyToEvent(Event $event): bool
    {
        if ($event->getCategory() !== 'music' || !$event->getArtistName()) {
            return false;
        }

        $url = $this->getArtistImage($event->getArtistName());
        if ($url === null) {
            return false;
        }

        $event->setArtistImageUrl($url);

        return true;
    }

    /**
     * Returns the public path of the locally stored artist picture,
     * downloading it from Deezer on first request. Null when unknown.
     */
    public function getArtistImage(string $artistName): ?string
    {
        $name = trim($artistName);
        if ($name === '') {
            return null;
        }

        $hash = sha1(mb_strtolower($name));
        $file = $this->uploadDir . '/' . $hash . '.jpg';
        // The ?v= version defeats the month-long browser/server caches on
        // /uploads when a file is ever re-downloaded under the same name.
        $publicPath = '/uploads/artists/' . $hash . '.jpg';

        if (file_exists($file)) {
            return $publicPath . '?v=' . filemtime($file);
        }

        $miss = $this->cache->getItem('deezer_artist_miss_' . $hash);
        if ($miss->isHit()) {
            return null;
        }

        try {
            $pictureUrl = $this->searchArtistPicture($name);

            // Names carrying extra words ("Sun brutalpop") often match nothing:
            // retry with trailing words stripped, but then only accept a real
            // name match — never the fuzzy fallback — to avoid wrong artists.
            $words = preg_split('/\s+/', $name) ?: [];
            while ($pictureUrl === null && count($words) > 1) {
                array_pop($words);
                $pictureUrl = $this->searchArtistPicture(implode(' ', $words), true);
            }

            if ($pictureUrl !== null && $this->download($pictureUrl, $hash)) {
                return $publicPath . '?v=' . time();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Deezer artist image fetch failed', [
                'artist' => $name,
                'error' => $e->getMessage(),
            ]);
        }

        $miss->set(true)->expiresAfter(self::MISS_TTL);
        $this->cache->save($miss);

        return null;
    }

    private function searchArtistPicture(string $name, bool $requireNameMatch = false): ?string
    {
        $response = $this->httpClient->request('GET', self::SEARCH_URL, [
            'query' => ['q' => $name, 'limit' => 15],
            'timeout' => 8,
        ]);

        if ($response->getStatusCode() !== 200) {
            $response->cancel();
            return null;
        }

        $results = $response->toArray()['data'] ?? [];
        if (!$results) {
            return null;
        }

        // Pick the best pictured candidate. Homonyms are common ("Korn" exists
        // three times, only "KoЯn" is the band), so an exact name match alone
        // is not enough: score = name proximity weighted by fan count.
        $target = $this->normalizeName($name);
        $bestScore = -1.0;
        $bestPicture = null;
        $firstPictured = null;

        foreach ($results as $artist) {
            $picture = $artist['picture_xl'] ?? $artist['picture_big'] ?? $artist['picture_medium'] ?? null;
            if (!$picture || $this->isPlaceholder($picture)) {
                continue;
            }
            $firstPictured ??= $picture;

            $candidate = $this->normalizeName($artist['name'] ?? '');
            $fans = (float) ($artist['nb_fan'] ?? 0);

            if ($candidate === $target) {
                $score = ($fans + 1) * 100; // exact name
            } elseif (strlen($target) >= 4 && levenshtein($candidate, $target) <= 1) {
                $score = $fans + 1; // stylised spelling (KoЯn, Megadeth…)
            } else {
                continue;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPicture = $picture;
            }
        }

        // Nothing matched by name: trust Deezer's fuzzy ranking, first hit
        // that actually has a photo.
        return $bestPicture ?? ($requireNameMatch ? null : $firstPictured);
    }

    /** Lowercased, accents-insensitive, alphanumeric only ("P.O.D." → "pod", "KoЯn" → "kon") */
    private function normalizeName(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower(trim($name))) ?: '';

        return preg_replace('/[^a-z0-9]/', '', $ascii) ?? '';
    }

    /**
     * Deezer serves a generic placeholder for artists without a photo: the
     * CDN path holds either no image hash or the md5 of the empty string.
     */
    private function isPlaceholder(string $pictureUrl): bool
    {
        return str_contains($pictureUrl, '/artist//')
            || str_contains($pictureUrl, 'd41d8cd98f00b204e9800998ecf8427e');
    }

    private function download(string $url, string $hash): bool
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 8]);
        if ($response->getStatusCode() !== 200) {
            $response->cancel();
            return false;
        }

        $content = $response->getContent();
        if ($content === '') {
            return false;
        }

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }

        return file_put_contents($this->uploadDir . '/' . $hash . '.jpg', $content) !== false;
    }
}
