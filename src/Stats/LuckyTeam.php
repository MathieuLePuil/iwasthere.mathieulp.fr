<?php

declare(strict_types=1);

namespace App\Stats;

use App\Entity\EventParticipation;

/**
 * Le bilan « porte-bonheur » : sur les matchs où l'utilisateur était présent,
 * quelle est la part de victoires de ses équipes ?
 *
 * Une équipe par sport collectif (voir User::favoriteTeams). Chaque équipe est un
 * texte libre : on la reconnaît dans un match du bon sport quand ce texte apparaît,
 * à la casse près, dans exactement un des deux camps du champ « A vs B » — présente
 * des deux côtés ou d'aucun, le match est écarté faute de pouvoir l'attribuer.
 *
 * Le résultat se lit sur getScoreline() : le vainqueur y vaut 1, 2 ou null. Un null
 * reste ambigu à dessein (voir Event::getScoreline) — il couvre le vrai match nul
 * comme le score que personne ne sait départager. On tranche donc le nul du reste
 * en relisant le score « a - b » ; tout le reste est compté « indécis » et sort du
 * taux de victoire, plutôt que d'y jouer à pile ou face.
 */
final class LuckyTeam
{
    private static function norm(string $s): string
    {
        return mb_strtolower(trim($s));
    }

    /**
     * @param EventParticipation[]      $parts
     * @param array<string, string>     $teamsBySport sport => nom d'équipe
     *
     * @return array{teams: list<array{
     *     sport: string, team: string, attended: int, wins: int, draws: int,
     *     losses: int, unknown: int, decided: int, win_rate: int|null,
     *     matches: list<array{participation: EventParticipation, result: string, side: int}>
     * }>}
     */
    public static function compute(array $parts, array $teamsBySport): array
    {
        $teams = [];
        foreach ($teamsBySport as $sport => $team) {
            $team = trim((string) $team);
            if ($team === '') {
                continue;
            }
            $one = self::computeOne($parts, $team, (string) $sport);
            if ($one['attended'] > 0) {
                $teams[] = $one;
            }
        }
        // Le plus de matchs vus en premier.
        usort($teams, fn ($a, $b) => $b['attended'] <=> $a['attended']);

        return ['teams' => $teams];
    }

    /**
     * @param EventParticipation[] $parts
     *
     * @return array{
     *     sport: string, team: string, attended: int, wins: int, draws: int,
     *     losses: int, unknown: int, decided: int, win_rate: int|null,
     *     matches: list<array{participation: EventParticipation, result: string, side: int}>
     * }
     */
    private static function computeOne(array $parts, string $team, string $sport): array
    {
        $fav = self::norm($team);
        $wins = 0;
        $draws = 0;
        $losses = 0;
        $unknown = 0;
        $matches = [];

        foreach ($parts as $p) {
            $e = $p->getEvent();
            // Le type porte le sport (football, rugby…) et implique la catégorie sport.
            if ($e->getType() !== $sport) {
                continue;
            }
            $teams = $e->getTeams();
            if (!$teams || !str_contains($teams, ' vs ')) {
                continue;
            }
            [$t1, $t2] = array_map('trim', explode(' vs ', $teams, 2));
            $in1 = str_contains(self::norm($t1), $fav);
            $in2 = str_contains(self::norm($t2), $fav);
            if ($in1 === $in2) {
                // L'équipe manque des deux côtés, ou figure des deux : inattribuable.
                continue;
            }
            $side = $in1 ? 1 : 2;

            $sl = $e->getScoreline();
            if ($sl !== null && $sl['winner'] !== null) {
                $result = $sl['winner'] === $side ? 'win' : 'loss';
            } elseif (
                preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/', (string) $e->getFinalScore(), $m)
                && (int) $m[1] === (int) $m[2]
            ) {
                $result = 'draw';
            } else {
                $result = 'unknown';
            }

            match ($result) {
                'win' => $wins++,
                'draw' => $draws++,
                'loss' => $losses++,
                default => $unknown++,
            };

            $matches[] = ['participation' => $p, 'result' => $result, 'side' => $side];
        }

        // Du plus récent au plus ancien pour l'affichage détaillé.
        usort($matches, fn ($a, $b) => $b['participation']->getEvent()->getDate()
            <=> $a['participation']->getEvent()->getDate());

        $decided = $wins + $draws + $losses;

        return [
            'sport' => $sport,
            'team' => trim($team),
            'attended' => $wins + $draws + $losses + $unknown,
            'wins' => $wins,
            'draws' => $draws,
            'losses' => $losses,
            'unknown' => $unknown,
            'decided' => $decided,
            'win_rate' => $decided > 0 ? (int) round($wins / $decided * 100) : null,
            'matches' => $matches,
        ];
    }
}
