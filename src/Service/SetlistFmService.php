<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SetlistFmService
{
    private const API_BASE = 'https://api.setlist.fm/rest/1.0';
    private const MAX_RETRIES = 24;
    /** Number of in-request retries when setlist.fm rate-limits us (HTTP 429). */
    private const RATE_LIMIT_RETRIES = 4;
    /** Base back-off between rate-limited retries (microseconds), multiplied by the attempt number. */
    private const RATE_LIMIT_BACKOFF_US = 1_200_000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
    ) {}

    public function forceReimportSetlist(Event $event): bool
    {
        $event->setSetlistRetryCount(0)->setSetlistLastAttemptAt(null);
        return $this->tryImportSetlist($event);
    }

    public function tryImportSetlist(Event $event): bool
    {
        if ($event->getCategory() !== 'music') {
            return false;
        }
        if (!$event->getArtistName()) {
            return false;
        }
        if ($event->getSetlistRetryCount() >= self::MAX_RETRIES) {
            return false;
        }

        try {
            $date = $event->getDate()->format('d-m-Y');

            $setlists = $this->searchSetlists($event->getArtistName(), $date);

            $event->setSetlistLastAttemptAt(new \DateTimeImmutable())
                ->setSetlistRetryCount($event->getSetlistRetryCount() + 1);

            if (empty($setlists)) {
                $this->em->flush();
                return false;
            }

            $bestSetlist = $this->findBestSetlist($setlists, $event);

            if ($bestSetlist) {
                $this->applySetlist($event, $bestSetlist);
                $this->em->flush();
                return true;
            }

            $this->em->flush();
            return false;

        } catch (SetlistFmRateLimitException $e) {
            // Transient: setlist.fm throttled us. Record the attempt time so we back off,
            // but do NOT burn the retry budget — the setlist may well exist.
            $this->logger->warning('Setlist.fm rate-limited, will retry later', [
                'event_id' => (string) $event->getId(),
            ]);
            $event->setSetlistLastAttemptAt(new \DateTimeImmutable());
            $this->em->flush();
            return false;
        } catch (\Throwable $e) {
            $this->logger->warning('Setlist.fm import failed', [
                'event_id' => (string) $event->getId(),
                'error' => $e->getMessage(),
            ]);
            $event->setSetlistRetryCount($event->getSetlistRetryCount() + 1)
                ->setSetlistLastAttemptAt(new \DateTimeImmutable());
            $this->em->flush();
            return false;
        }
    }

    /**
     * Search setlist.fm, retrying with back-off on HTTP 429 (free plan: 2 req/s).
     *
     * @return array<int, array<string, mixed>> the raw `setlist` array (empty when none)
     *
     * @throws SetlistFmRateLimitException when still throttled after RATE_LIMIT_RETRIES
     */
    private function searchSetlists(string $artistName, string $date): array
    {
        for ($attempt = 1; ; $attempt++) {
            $response = $this->httpClient->request('GET', self::API_BASE . '/search/setlists', [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'Accept' => 'application/json',
                ],
                'query' => [
                    'artistName' => $artistName,
                    'date' => $date,
                    'p' => 1,
                ],
            ]);

            // getStatusCode() does not throw on 4xx/5xx, so we can inspect it safely.
            $status = $response->getStatusCode();

            if ($status === 429) {
                $response->cancel();
                if ($attempt <= self::RATE_LIMIT_RETRIES) {
                    usleep(self::RATE_LIMIT_BACKOFF_US * $attempt);
                    continue;
                }
                throw new SetlistFmRateLimitException('setlist.fm rate limit exceeded after retries');
            }

            // setlist.fm answers 404 when no setlist matches the artist/date.
            if ($status === 404) {
                return [];
            }

            $data = $response->toArray();
            return $data['setlist'] ?? [];
        }
    }

    private function findBestSetlist(array $setlists, Event $event): ?array
    {
        if ($event->getVenue()) {
            foreach ($setlists as $setlist) {
                $venueName = $setlist['venue']['name'] ?? '';
                if ($venueName && stripos($venueName, $event->getVenue()->getName()) !== false) {
                    return $setlist;
                }
            }
        }

        return $setlists[0] ?? null;
    }

    private function applySetlist(Event $event, array $setlist): void
    {
        $songs = [];
        $encores = [];

        foreach ($setlist['sets']['set'] ?? [] as $set) {
            $isEncore = !empty($set['encore']);
            foreach ($set['song'] ?? [] as $song) {
                $name = $song['name'] ?? '';
                if (empty($name)) {
                    continue;
                }
                $entry = [
                    'name' => $name,
                    'tape' => !empty($song['tape']),
                    'info' => $song['info'] ?? null ?: null,
                    'with' => isset($song['with']['name']) ? $song['with']['name'] : null,
                ];
                if ($isEncore) {
                    $encores[] = $entry;
                } else {
                    $songs[] = $entry;
                }
            }
        }

        $tourName = $setlist['tour']['name'] ?? null;

        $event->setSetlist($songs)
            ->setSetlistEncores($encores)
            ->setSetlistSource('setlist_fm')
            ->setSetlistUrl($setlist['url'] ?? null)
            ->setSetlistImportedAt(new \DateTimeImmutable());

        if ($tourName && !$event->getTourName()) {
            $event->setTourName($tourName);
        }
    }
}
