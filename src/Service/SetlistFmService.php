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

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
    ) {}

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

            $response = $this->httpClient->request('GET', self::API_BASE . '/search/setlists', [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'Accept' => 'application/json',
                ],
                'query' => [
                    'artistName' => $event->getArtistName(),
                    'date' => $date,
                    'p' => 1,
                ],
            ]);

            $data = $response->toArray();
            $setlists = $data['setlist'] ?? [];

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
