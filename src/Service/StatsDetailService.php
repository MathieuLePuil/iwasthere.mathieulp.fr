<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EventParticipation;
use App\Entity\User;
use App\Repository\EventParticipationRepository;

class StatsDetailService
{
    public const TOPICS = [
        'evenements', 'lieux', 'notes', 'repartition', 'artistes',
        'festivals', 'annees', 'mois', 'series', 'periode', 'compagnons',
    ];

    public function __construct(private readonly EventParticipationRepository $repo)
    {
    }

    public function compute(User $user, string $topic): ?array
    {
        $parts = $this->repo->findPastParticipations($user);
        if ($parts === []) {
            return null;
        }

        return match ($topic) {
            'evenements' => $this->events($parts),
            'lieux' => $this->venues($parts),
            'notes' => $this->ratings($parts),
            'repartition' => $this->breakdown($parts),
            'artistes' => $this->artists($parts),
            'festivals' => $this->festivals($parts),
            'annees' => $this->years($parts),
            'mois' => $this->months($parts),
            'series' => $this->streaks($parts),
            'periode' => $this->period($parts),
            'compagnons' => $this->friends($parts),
            default => null,
        };
    }

    /** @param EventParticipation[] $parts */
    private function events(array $parts): array
    {
        $groups = [];
        foreach (array_reverse($parts) as $p) {
            $groups[$p->getEvent()->getDate()->format('Y')][] = $p;
        }

        return ['groups' => $groups, 'total' => count($parts)];
    }

    /** @param EventParticipation[] $parts */
    private function venues(array $parts): array
    {
        $venues = [];
        $noVenue = 0;
        foreach ($parts as $p) {
            $v = $p->getEvent()->getVenue();
            if (!$v) {
                $noVenue++;
                continue;
            }
            $name = $v->getName();
            $venues[$name] ??= [
                'name' => $name, 'count' => 0,
                'total_rating' => 0, 'rating_count' => 0,
                'first' => null, 'last' => null,
            ];
            $venues[$name]['count']++;
            if ($p->getRating()) {
                $venues[$name]['total_rating'] += $p->getRating();
                $venues[$name]['rating_count']++;
            }
            $date = $p->getEvent()->getDate();
            if (!$venues[$name]['first'] || $date < $venues[$name]['first']) {
                $venues[$name]['first'] = $date;
            }
            if (!$venues[$name]['last'] || $date > $venues[$name]['last']) {
                $venues[$name]['last'] = $date;
            }
        }

        foreach ($venues as &$v) {
            $v['avg_rating'] = $v['rating_count'] > 0 ? round($v['total_rating'] / $v['rating_count'], 1) : null;
            unset($v['total_rating'], $v['rating_count']);
        }
        unset($v);
        usort($venues, fn ($a, $b) => $b['count'] <=> $a['count']);

        return ['venues' => $venues, 'no_venue' => $noVenue, 'total' => count($parts)];
    }

    /** @param EventParticipation[] $parts */
    private function ratings(array $parts): array
    {
        $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $sum = 0;
        $rated = 0;
        $unrated = 0;
        $byType = [];
        $ratedParts = [];

        foreach ($parts as $p) {
            $r = $p->getRating();
            if (!$r) {
                $unrated++;
                continue;
            }
            $distribution[$r]++;
            $sum += $r;
            $rated++;
            $ratedParts[] = $p;

            $type = $p->getEvent()->getType();
            $byType[$type] ??= ['type' => $type, 'sum' => 0, 'count' => 0];
            $byType[$type]['sum'] += $r;
            $byType[$type]['count']++;
        }

        foreach ($byType as &$t) {
            $t['avg'] = round($t['sum'] / $t['count'], 1);
            unset($t['sum']);
        }
        unset($t);
        usort($byType, fn ($a, $b) => $b['avg'] <=> $a['avg']);

        usort($ratedParts, function (EventParticipation $a, EventParticipation $b) {
            return [$b->getRating(), $b->getEvent()->getDate()] <=> [$a->getRating(), $a->getEvent()->getDate()];
        });

        return [
            'distribution' => $distribution,
            'rated' => $rated,
            'unrated' => $unrated,
            'avg' => $rated > 0 ? round($sum / $rated, 1) : null,
            'by_type' => $byType,
            'best' => array_slice($ratedParts, 0, 10),
        ];
    }

    /** @param EventParticipation[] $parts */
    private function breakdown(array $parts): array
    {
        $types = [];
        $byYear = [];
        foreach ($parts as $p) {
            $e = $p->getEvent();
            $type = $e->getType();
            $types[$type] = ($types[$type] ?? 0) + 1;

            $year = $e->getDate()->format('Y');
            $byYear[$year] ??= ['music' => 0, 'sport' => 0];
            $byYear[$year][$e->getCategory() === 'music' ? 'music' : 'sport']++;
        }
        arsort($types);
        krsort($byYear);

        return ['types' => $types, 'by_year' => $byYear, 'total' => count($parts)];
    }

    /** @param EventParticipation[] $parts */
    private function artists(array $parts): array
    {
        $variants = [];
        foreach ($parts as $p) {
            $e = $p->getEvent();
            if ($e->getCategory() !== 'music' || !$e->getArtistName()) {
                continue;
            }
            $name = trim($e->getArtistName());
            $key = mb_strtolower($name);
            $variants[$key] ??= [
                'names' => [], 'count' => 0,
                'total_rating' => 0, 'rating_count' => 0,
                'first' => null, 'last' => null, 'image' => null,
            ];
            $variants[$key]['names'][$name] = ($variants[$key]['names'][$name] ?? 0) + 1;
            $variants[$key]['image'] ??= $e->getArtistImageUrl();
            $variants[$key]['count']++;
            if ($p->getRating()) {
                $variants[$key]['total_rating'] += $p->getRating();
                $variants[$key]['rating_count']++;
            }
            $date = $e->getDate();
            if (!$variants[$key]['first'] || $date < $variants[$key]['first']) {
                $variants[$key]['first'] = $date;
            }
            if (!$variants[$key]['last'] || $date > $variants[$key]['last']) {
                $variants[$key]['last'] = $date;
            }
        }

        $artists = [];
        foreach ($variants as $v) {
            arsort($v['names']);
            $artists[] = [
                'name' => array_key_first($v['names']),
                'image' => $v['image'],
                'count' => $v['count'],
                'avg_rating' => $v['rating_count'] > 0 ? round($v['total_rating'] / $v['rating_count'], 1) : null,
                'first' => $v['first'],
                'last' => $v['last'],
            ];
        }
        usort($artists, fn ($a, $b) => [$b['count'], mb_strtolower($a['name'])] <=> [$a['count'], mb_strtolower($b['name'])]);

        return ['artists' => $artists, 'total' => count($artists)];
    }

    /** @param EventParticipation[] $parts */
    private function festivals(array $parts): array
    {
        $groups = [];
        foreach ($parts as $p) {
            $e = $p->getEvent();
            if ($e->getCategory() !== 'music' || $e->getType() !== 'festival') {
                continue;
            }
            $name = $e->getVenue()?->getName() ?? 'Inconnu';
            $year = $e->getDate()->format('Y');
            $key = $name . '|' . $year;
            $groups[$key] ??= [
                'name' => $name, 'year' => (int) $year, 'count' => 0,
                'total_rating' => 0, 'rating_count' => 0, 'artists' => [],
            ];
            $groups[$key]['count']++;
            if ($p->getRating()) {
                $groups[$key]['total_rating'] += $p->getRating();
                $groups[$key]['rating_count']++;
            }
            if ($e->getArtistName()) {
                $groups[$key]['artists'][] = trim($e->getArtistName());
            }
        }

        $names = [];
        $totalConcerts = 0;
        foreach ($groups as &$g) {
            $g['avg_rating'] = $g['rating_count'] > 0 ? round($g['total_rating'] / $g['rating_count'], 1) : null;
            unset($g['total_rating'], $g['rating_count']);
            $names[$g['name']] = true;
            $totalConcerts += $g['count'];
        }
        unset($g);
        usort($groups, fn ($a, $b) => [$b['count'], $b['year']] <=> [$a['count'], $a['year']]);

        return [
            'groups' => $groups,
            'distinct_count' => count($names),
            'editions_count' => count($groups),
            'total_concerts' => $totalConcerts,
        ];
    }

    /** @param EventParticipation[] $parts */
    private function years(array $parts): array
    {
        $years = [];
        foreach ($parts as $p) {
            $e = $p->getEvent();
            $y = $e->getDate()->format('Y');
            $years[$y] ??= [
                'year' => (int) $y, 'total' => 0, 'concerts' => 0, 'festivals' => 0,
                'sports' => 0, 'total_rating' => 0, 'rating_count' => 0,
            ];
            $years[$y]['total']++;
            if ($e->getCategory() === 'music') {
                $years[$y][$e->getType() === 'festival' ? 'festivals' : 'concerts']++;
            } else {
                $years[$y]['sports']++;
            }
            if ($p->getRating()) {
                $years[$y]['total_rating'] += $p->getRating();
                $years[$y]['rating_count']++;
            }
        }

        foreach ($years as &$y) {
            $y['avg_rating'] = $y['rating_count'] > 0 ? round($y['total_rating'] / $y['rating_count'], 1) : null;
            unset($y['total_rating'], $y['rating_count']);
        }
        unset($y);
        krsort($years);

        return ['years' => array_values($years), 'max' => max(array_column($years, 'total'))];
    }

    /** @param EventParticipation[] $parts */
    private function months(array $parts): array
    {
        $months = array_fill(1, 12, 0);
        $weekdays = array_fill(1, 7, 0);
        foreach ($parts as $p) {
            $date = $p->getEvent()->getDate();
            $months[(int) $date->format('n')]++;
            $weekdays[(int) $date->format('N')]++;
        }

        return [
            'months' => $months,
            'weekdays' => $weekdays,
            'top_month' => array_search(max($months), $months, true),
            'top_weekday' => array_search(max($weekdays), $weekdays, true),
            'total' => count($parts),
        ];
    }

    /** @param EventParticipation[] $parts */
    private function streaks(array $parts): array
    {
        $byDay = [];
        foreach ($parts as $p) {
            $d = $p->getEvent()->getDate()->format('Y-m-d');
            $byDay[$d] = ($byDay[$d] ?? 0) + 1;
        }
        ksort($byDay);
        $dates = array_keys($byDay);

        $runs = [];
        $runStart = $dates[0];
        $prev = $dates[0];
        $len = 1;
        for ($i = 1, $n = count($dates); $i < $n; $i++) {
            $curr = $dates[$i];
            $diff = (int) (new \DateTimeImmutable($prev))->diff(new \DateTimeImmutable($curr))->days;
            if ($diff === 1) {
                $len++;
            } else {
                $runs[] = ['start' => $runStart, 'end' => $prev, 'length' => $len];
                $runStart = $curr;
                $len = 1;
            }
            $prev = $curr;
        }
        $runs[] = ['start' => $runStart, 'end' => $prev, 'length' => $len];

        $lastRun = end($runs);
        $daysSinceLast = (int) (new \DateTimeImmutable($lastRun['end']))->diff(new \DateTimeImmutable('today'))->days;
        $currentStreak = $daysSinceLast <= 1 ? $lastRun['length'] : 0;

        $streakRuns = array_values(array_filter($runs, fn ($r) => $r['length'] > 1));
        usort($streakRuns, fn ($a, $b) => [$b['length'], $b['start']] <=> [$a['length'], $a['start']]);

        $busiestCount = max($byDay);
        $busiestDate = array_search($busiestCount, $byDay, true);

        return [
            'max_streak' => max(array_column($runs, 'length')),
            'current_streak' => $currentStreak,
            'runs' => array_slice($streakRuns, 0, 10),
            'active_days' => count($dates),
            'multi_event_days' => count(array_filter($byDay, fn ($c) => $c > 1)),
            'busiest' => $busiestCount > 1 ? ['date' => $busiestDate, 'count' => $busiestCount] : null,
        ];
    }

    /** @param EventParticipation[] $parts */
    private function period(array $parts): array
    {
        $first = $parts[0];
        $last = $parts[count($parts) - 1];
        $firstDate = $first->getEvent()->getDate();
        $lastDate = $last->getEvent()->getDate();
        $span = $firstDate->diff($lastDate);

        $years = [];
        foreach ($parts as $p) {
            $years[$p->getEvent()->getDate()->format('Y')] = true;
        }

        return [
            'first' => $first,
            'last' => $last,
            'span_days' => (int) $span->days,
            'span_years' => $span->y,
            'span_months' => $span->m,
            'active_years' => count($years),
            'avg_per_year' => round(count($parts) / count($years), 1),
            'total' => count($parts),
        ];
    }

    /** @param EventParticipation[] $parts */
    private function friends(array $parts): array
    {
        $friends = [];
        foreach ($parts as $p) {
            foreach ($p->getFriends() as $f) {
                $name = $f['displayName'] ?? ($f['friendUserId'] ?? 'Inconnu');
                $friends[$name] ??= ['name' => $name, 'count' => 0, 'last' => null];
                $friends[$name]['count']++;
                $date = $p->getEvent()->getDate();
                if (!$friends[$name]['last'] || $date > $friends[$name]['last']) {
                    $friends[$name]['last'] = $date;
                }
            }
        }
        usort($friends, fn ($a, $b) => $b['count'] <=> $a['count']);

        return ['friends' => array_values($friends), 'total' => count($friends)];
    }
}
