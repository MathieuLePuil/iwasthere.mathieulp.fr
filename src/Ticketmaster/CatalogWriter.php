<?php

declare(strict_types=1);

namespace App\Ticketmaster;

use App\Repository\TmEventRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Écrit les projections du flux dans `tm_event` et `tm_sale_window`, par lots
 * de 500, en SQL direct : l'ORM à l'unité serait dix fois trop lent pour un
 * catalogue entier chaque heure.
 *
 * Une ligne dont le hash n'a pas changé ne voit que son `last_seen_at`
 * rafraîchi — la grande majorité, d'un passage à l'autre. Le tout dans une
 * seule transaction : un échec au milieu ne laisse pas la moitié du catalogue
 * à jour et l'autre marquée « disparue ».
 */
final class CatalogWriter
{
    public const BATCH_SIZE = 500;

    private const EVENT_COLUMNS = [
        'event_id', 'name', 'name_normalized', 'status', 'event_start_utc', 'event_start_local_date',
        'event_start_local_time', 'venue_name', 'venue_city', 'venue_timezone', 'segment', 'genre',
        'url', 'source', 'artist_name', 'artists_normalized', 'payload_hash',
    ];

    public function __construct(
        private readonly Connection $conn,
        private readonly TmEventRepository $events,
    ) {}

    /**
     * @param array<string, array<string, mixed>> $rows event_id → projection (EventProjector)
     *
     * @return array{inserted: int, updated: int, unchanged: int, new_ids: list<string>} new_ids : les événements qui n'étaient pas au catalogue (ArtistAnnouncer)
     */
    public function write(array $rows, \DateTimeImmutable $seenAt): array
    {
        $hashes = $this->events->loadHashes();
        $stats = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'new_ids' => []];
        $seen = $seenAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $this->conn->transactional(function () use ($rows, $hashes, $seen, &$stats): void {
            $unchanged = [];
            $changed = [];
            foreach ($rows as $id => $row) {
                if (isset($hashes[$id]) && $hashes[$id] === $row['payload_hash']) {
                    $unchanged[] = $id;
                    $stats['unchanged']++;
                } elseif (isset($hashes[$id])) {
                    $changed[] = $row;
                    $stats['updated']++;
                } else {
                    $changed[] = $row;
                    $stats['inserted']++;
                    $stats['new_ids'][] = $id;
                }

                if (count($unchanged) >= self::BATCH_SIZE) {
                    $this->touch($unchanged, $seen);
                    $unchanged = [];
                }
                if (count($changed) >= self::BATCH_SIZE) {
                    $this->upsert($changed, $seen);
                    $changed = [];
                }
            }
            $this->touch($unchanged, $seen);
            $this->upsert($changed, $seen);
        });

        return $stats;
    }

    /** @param list<string> $ids */
    private function touch(array $ids, string $seen): void
    {
        if ($ids === []) {
            return;
        }
        $this->conn->executeStatement(
            'UPDATE tm_event SET last_seen_at = ? WHERE event_id IN (?)',
            [$seen, $ids],
            [ParameterType::STRING, ArrayParameterType::STRING],
        );
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsert(array $rows, string $seen): void
    {
        if ($rows === []) {
            return;
        }

        $this->upsertEvents($rows, $seen);
        $this->upsertWindows($rows);
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsertEvents(array $rows, string $seen): void
    {
        $columns = [...self::EVENT_COLUMNS, 'first_seen_at', 'last_seen_at'];
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $params = [];
        foreach ($rows as $row) {
            foreach (self::EVENT_COLUMNS as $column) {
                $params[] = $row[$column];
            }
            $params[] = $seen;
            $params[] = $seen;
        }

        $updates = array_map(
            static fn (string $c) => "$c = VALUES($c)",
            array_diff($columns, ['event_id', 'first_seen_at']),
        );

        $this->conn->executeStatement(sprintf(
            'INSERT INTO tm_event (%s) VALUES %s ON DUPLICATE KEY UPDATE %s',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($rows), $placeholders)),
            implode(', ', $updates),
        ), $params);
    }

    /**
     * Les fenêtres de vente : upsert sur (event_id, type, label). Les préventes
     * que le flux ne cite plus sont retirées — aujourd'hui il n'y en a aucune,
     * mais le jour où il y en aura, une prévente disparue ne doit pas
     * continuer à déclencher des alertes.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function upsertWindows(array $rows): void
    {
        $params = [];
        $count = 0;
        $withoutPresale = [];
        foreach ($rows as $row) {
            $presaleLabels = [];
            foreach ($row['windows'] as $window) {
                $params[] = Uuid::v7()->toBinary();
                $params[] = $row['event_id'];
                $params[] = $window['type'];
                $params[] = $window['label'];
                $params[] = $window['starts_at_utc'];
                $params[] = $window['ends_at_utc'];
                $params[] = $window['url'];
                $count++;
                if ($window['type'] === SaleWindowType::Presale->value) {
                    $presaleLabels[] = $window['label'];
                }
            }
            if ($presaleLabels === []) {
                $withoutPresale[] = $row['event_id'];
            } else {
                $this->conn->executeStatement(
                    "DELETE FROM tm_sale_window WHERE event_id = ? AND type = 'presale' AND label NOT IN (?)",
                    [$row['event_id'], $presaleLabels],
                    [ParameterType::STRING, ArrayParameterType::STRING],
                );
            }
        }

        if ($withoutPresale !== []) {
            $this->conn->executeStatement(
                "DELETE FROM tm_sale_window WHERE type = 'presale' AND event_id IN (?)",
                [$withoutPresale],
                [ArrayParameterType::STRING],
            );
        }

        if ($count === 0) {
            return;
        }

        $this->conn->executeStatement(sprintf(
            'INSERT INTO tm_sale_window (id, event_id, type, label, starts_at_utc, ends_at_utc, url) VALUES %s
             ON DUPLICATE KEY UPDATE starts_at_utc = VALUES(starts_at_utc), ends_at_utc = VALUES(ends_at_utc), url = VALUES(url)',
            implode(', ', array_fill(0, $count, '(?, ?, ?, ?, ?, ?, ?)')),
        ), $params);
    }
}
