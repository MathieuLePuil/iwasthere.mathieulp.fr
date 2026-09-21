<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Entity\TmEvent;
use App\Ticketmaster\NameNormalizer;
use App\Ticketmaster\SearchCriteria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TmEvent>
 */
class TmEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TmEvent::class);
    }

    /**
     * La recherche du catalogue (F1). En SQL parce que MATCH … AGAINST et les
     * fonctions de fenêtre n'existent pas en DQL ; le second temps recharge les
     * entités avec leurs fenêtres, dans l'ordre trouvé.
     *
     * Mode artiste (défaut) : le terme est un nom d'artiste, cherché dans
     * `artists_normalized` (nom entier ou préfixe d'un nom) — « muse » donne
     * Muse, pas les musées. Les événements sans artiste dans le flux (32 %)
     * sont rattrapés par leur nom quand il commence par le terme. Mode « tout » :
     * plein texte et LIKE sur le nom, LIKE sur la salle.
     *
     * Regroupement : Ticketmaster publie un événement par offre (vente standard,
     * vente co-promue, loges, hospitalité…) avec le même nom, la même salle et
     * la même date — trois « MUSE » le 27/11 à Nanterre. On ne montre qu'une
     * carte par concert : même artiste (ou nom), salle, date et heure. Elle
     * représente l'offre dont la billetterie ouvre en premier ; à égalité, celle
     * dont le nom commence par l'artiste, puis la plus courte — la vente
     * standard « MUSE » plutôt que « PRESTATION HOSPITALITE MUSE » ou
     * « UPGRADE HAIDEN HENDERSON ».
     *
     * @return array{events: list<TmEvent>, total: int, offers: array<string, int>} offers : event_id → nombre d'offres regroupées
     */
    public function search(SearchCriteria $criteria, \DateTimeImmutable $now, int $limit, int $offset = 0): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $where = ['1 = 1'];
        $params = ['now' => $now->format('Y-m-d H:i:s')];
        $exactFirst = '0';

        if ($criteria->query !== '') {
            $normalized = NameNormalizer::normalize($criteria->query);
            if ($normalized === '') {
                return ['events' => [], 'total' => 0, 'offers' => []];
            }
            if ($criteria->byArtist()) {
                $where[] = '(e.artists_normalized LIKE :artist_prefix OR (e.artists_normalized IS NULL AND (e.name_normalized = :exact OR e.name_normalized LIKE :name_prefix)))';
                $params['artist_prefix'] = '%| ' . LikeEscaper::escape($normalized) . '%';
                $params['artist_exact'] = '%| ' . LikeEscaper::escape($normalized) . ' |%';
                $params['exact'] = $normalized;
                $params['name_prefix'] = LikeEscaper::escape($normalized) . ' %';
                // « muse » entier avant « museum » ou « muse by soaked »
                $exactFirst = 'CASE WHEN e.artists_normalized LIKE :artist_exact OR e.name_normalized = :exact THEN 0 ELSE 1 END';
            } else {
                $or = ['e.name_normalized LIKE :like', 'e.venue_name LIKE :like_raw'];
                $params['like'] = LikeEscaper::contains($normalized);
                $params['like_raw'] = LikeEscaper::contains($criteria->query);
                if (($boolean = NameNormalizer::booleanQuery($criteria->query)) !== '') {
                    $or[] = 'MATCH(e.name_normalized) AGAINST(:ft IN BOOLEAN MODE)';
                    $params['ft'] = $boolean;
                }
                $where[] = '(' . implode(' OR ', $or) . ')';
            }
        }
        if (!$criteria->allSegments()) {
            $where[] = 'e.segment = :segment';
            $params['segment'] = $criteria->segment;
        }
        if ($criteria->city !== '') {
            $where[] = 'e.venue_city = :city';
            $params['city'] = $criteria->city;
        }
        if ($criteria->from !== null) {
            $where[] = 'e.event_start_local_date >= :from';
            $params['from'] = $criteria->from->format('Y-m-d');
        }
        if ($criteria->to !== null) {
            $where[] = 'e.event_start_local_date <= :to';
            $params['to'] = $criteria->to->format('Y-m-d');
        }
        // « Déjà en vente » = ouverte et vendable ; « à venir » = ouverture future.
        // Les annulés n'ont rien à vendre dans les deux cas.
        $where[] = "e.status <> 'cancelled'";
        $where[] = $criteria->wantsOpen()
            ? "w.starts_at_utc <= :now AND (w.ends_at_utc IS NULL OR w.ends_at_utc > :now) AND e.status = 'onsale'"
            : 'w.starts_at_utc > :now';

        // Une ligne par offre, numérotée dans son concert : la première représente le concert
        $concert = 'COALESCE(e.artists_normalized, e.name_normalized), e.venue_name, e.event_start_local_date, e.event_start_local_time';
        // Le premier artiste de « | a | b | » est « a » ; un nom qui commence par lui est l'offre principale
        $mainOffer = "CASE WHEN e.artists_normalized IS NULL OR e.name_normalized LIKE CONCAT(SUBSTRING_INDEX(SUBSTRING(e.artists_normalized, 4), ' | ', 1), '%') THEN 0 ELSE 1 END";
        $offers = sprintf(
            "SELECT e.event_id, e.name, e.event_start_utc, w.starts_at_utc, %s AS exact_first,
                    ROW_NUMBER() OVER (PARTITION BY %s ORDER BY w.starts_at_utc, %s, CHAR_LENGTH(e.name), e.url, e.event_id) AS rn,
                    COUNT(*) OVER (PARTITION BY %s) AS offers
             FROM tm_event e
             JOIN tm_sale_window w ON w.event_id = e.event_id AND w.type = 'public'
             WHERE %s",
            $exactFirst,
            $concert,
            $mainOffer,
            $concert,
            implode(' AND ', $where),
        );

        $total = (int) $conn->fetchOne("SELECT COUNT(*) FROM ($offers) o WHERE o.rn = 1", $params);
        if ($total === 0) {
            return ['events' => [], 'total' => 0, 'offers' => []];
        }

        $order = $criteria->wantsOpen() ? 'o.event_start_utc ASC, o.name ASC' : 'o.starts_at_utc ASC, o.name ASC';
        /** @var list<array{event_id: string, offers: int|string}> $rows */
        $rows = $conn->fetchAllAssociative(
            "SELECT o.event_id, o.offers FROM ($offers) o WHERE o.rn = 1 ORDER BY o.exact_first, $order LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
            $params,
        );

        $offersById = [];
        foreach ($rows as $row) {
            $offersById[$row['event_id']] = (int) $row['offers'];
        }

        return ['events' => $this->findWithWindows(array_keys($offersById)), 'total' => $total, 'offers' => $offersById];
    }

    /**
     * Les événements demandés, fenêtres chargées, dans l'ordre des ids.
     *
     * @param list<string> $ids
     *
     * @return list<TmEvent>
     */
    public function findWithWindows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var list<TmEvent> $events */
        $events = $this->createQueryBuilder('e')
            ->addSelect('w')
            ->leftJoin('e.saleWindows', 'w')
            ->where('e.eventId IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($events as $event) {
            $byId[$event->getEventId()] = $event;
        }

        return array_values(array_filter(array_map(static fn (string $id) => $byId[$id] ?? null, $ids)));
    }

    public function findOneWithWindows(string $eventId): ?TmEvent
    {
        return $this->findWithWindows([$eventId])[0] ?? null;
    }

    /**
     * Les villes du segment, les plus fournies d'abord — pour le filtre.
     *
     * @return list<string>
     */
    public function findCities(SearchCriteria $criteria, \DateTimeImmutable $now, int $limit = 40): array
    {
        $qb = $this->createQueryBuilder('e')
            ->select('e.venueCity AS city, COUNT(e.eventId) AS n')
            ->join('e.saleWindows', 'w', 'WITH', 'w.type = :public')
            ->where('e.venueCity IS NOT NULL')
            ->andWhere('e.status <> :cancelled')
            ->andWhere('w.startsAtUtc > :now')
            ->setParameter('public', 'public')
            ->setParameter('cancelled', 'cancelled')
            ->setParameter('now', $now, UtcDateTimeImmutableType::NAME)
            ->groupBy('e.venueCity')
            ->orderBy('n', 'DESC')
            ->addOrderBy('city', 'ASC')
            ->setMaxResults($limit);
        if (!$criteria->allSegments()) {
            $qb->andWhere('e.segment = :segment')->setParameter('segment', $criteria->segment);
        }

        /** @var list<array{city: string, n: int|string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $cities = array_column($rows, 'city');
        sort($cities, SORT_LOCALE_STRING);

        return $cities;
    }

    /**
     * Les segments présents au catalogue, par volume décroissant.
     *
     * @return list<string>
     */
    public function findSegments(): array
    {
        /** @var list<array{segment: string}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.segment AS segment, COUNT(e.eventId) AS n')
            ->where('e.segment IS NOT NULL')
            ->groupBy('e.segment')
            ->orderBy('n', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'segment');
    }

    /**
     * Hash courant de chaque ligne du catalogue : la synchronisation ne réécrit
     * que ce qui a changé. 61 000 paires de courtes chaînes, quelques Mo.
     *
     * @return array<string, string> event_id → payload_hash
     */
    public function loadHashes(): array
    {
        /** @var array<string, string> $hashes */
        $hashes = $this->getEntityManager()->getConnection()
            ->fetchAllKeyValue('SELECT event_id, payload_hash FROM tm_event');

        return $hashes;
    }

    /**
     * Purge : les événements que le flux n'a plus cités depuis `$before` et que
     * personne ne suit. Les fenêtres partent en cascade. (CGU Ticketmaster : ne
     * pas conserver le contenu au-delà du nécessaire.)
     */
    public function purgeUnseen(\DateTimeImmutable $before): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE e FROM tm_event e
             LEFT JOIN event_watch ew ON ew.event_id = e.event_id
             WHERE e.last_seen_at < :before AND ew.id IS NULL',
            ['before' => $before->format('Y-m-d H:i:s')],
        );
    }
}
