<?php

declare(strict_types=1);

namespace App\Rewind;

use App\Entity\EventParticipation;
use App\Entity\User;
use App\Repository\EventParticipationRepository;

/**
 * Construit le Rewind : le bilan d'une année en diapositives.
 *
 * Chaque diapo est un tableau autonome — assez pour être rendue en HTML animé
 * comme pour être redessinée sur un canvas 1080×1920 à télécharger. Les deux
 * rendus lisent la même structure, c'est ce qui garantit qu'une story ressemble
 * à ce qu'on a vu à l'écran.
 *
 * Une diapo sans matière n'est pas produite : mieux vaut un Rewind court qu'un
 * « Ton artiste de l'année : — ».
 */
final class RewindService
{
    /** En dessous, l'année est trop maigre pour raconter quoi que ce soit */
    private const MIN_EVENTS = 3;

    /** La note qui fait un coup de cœur */
    private const TOP_RATING = 5;

    /**
     * Budget de l'énumération, en caractères. Un plafond sur le nombre de noms
     * ne protège de rien : vingt titres courts tiennent, vingt titres à rallonge
     * débordent de la story. C'est la longueur qui décide.
     */
    private const PROSE_MAX_CHARS = 430;

    private const MONTHS_FR = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    /**
     * Un accent par diapo. Tous dérivent autour du violet de l'app (--positive,
     * #B060FF) sans en sortir : le diaporama avance visuellement, mais reste
     * chez nous. Le fond, lui, est le noir de l'app dans tous les cas.
     */
    private const ACCENTS = [
        '#B060FF', // le violet de marque, pour l'ouverture
        '#7C5CFF',
        '#D946EF',
        '#8B5CF6',
        '#C879FF',
        '#6D8BFF',
        '#A855F7',
        '#E879F9',
        '#818CF8',
    ];

    public function __construct(
        private readonly EventParticipationRepository $repo,
    ) {}

    /** Les années pour lesquelles l'utilisateur a de quoi faire un Rewind. */
    public function availableYears(User $user): array
    {
        $years = [];
        foreach ($this->repo->findPastParticipations($user) as $p) {
            $y = (int) $p->getEvent()->getDate()->format('Y');
            $years[$y] = ($years[$y] ?? 0) + 1;
        }

        $years = array_keys(array_filter($years, fn (int $n) => $n >= self::MIN_EVENTS));
        rsort($years);

        return $years;
    }

    /**
     * @return array{year: int, total: int, slides: list<array>}|null
     *     null si l'année ne contient pas de quoi raconter
     */
    public function build(User $user, int $year): ?array
    {
        $parts = $this->repo->findForYear($user, $year);
        if (count($parts) < self::MIN_EVENTS) {
            return null;
        }

        $slides = array_values(array_filter([
            $this->intro($year, $parts),
            $this->counts($parts),
            $this->topArtist($parts),
            $this->artistList($parts),
            $this->hours($parts),
            $this->topVenue($parts),
            $this->topCompanion($parts),
            $this->busiestMonth($parts),
            $this->ratings($parts),
            $this->topSong($parts),
        ]));

        $slides[] = $this->outro($year, $parts);

        // Accent et numérotation suivent l'ordre final : les diapos écartées ne
        // laissent ni trou dans le compteur ni saut dans la couleur
        $total = count($slides);
        foreach ($slides as $i => &$slide) {
            $slide['accent'] = $slide['accent'] ?? self::ACCENTS[$i % count(self::ACCENTS)];
            $slide['index'] = $i + 1;
            $slide['count'] = $total;
        }
        unset($slide);

        return ['year' => $year, 'total' => count($parts), 'slides' => $slides];
    }

    /** @param EventParticipation[] $parts */
    private function intro(int $year, array $parts): array
    {
        return [
            'key' => 'intro',
            'kind' => 'intro',
            'eyebrow' => 'IWasThere',
            'title' => (string) $year,
            'subtitle' => count($parts) . ' ' . $this->plural(count($parts), 'événement'),
            'note' => 'Ton année en concerts',
        ];
    }

    /** @param EventParticipation[] $parts */
    private function counts(array $parts): array
    {
        $byType = [];
        foreach ($parts as $p) {
            $type = $p->getEvent()->getType();
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }
        arsort($byType);

        $labels = [
            'concert' => 'concerts', 'festival' => 'festivals',
            'football' => 'matchs de foot', 'rugby' => 'matchs de rugby', 'tennis' => 'matchs de tennis',
        ];

        return [
            'key' => 'counts',
            'kind' => 'number',
            'eyebrow' => 'En tout',
            'title' => (string) count($parts),
            'subtitle' => $this->plural(count($parts), 'sortie'),
            'items' => array_map(
                fn ($type, $n) => ['label' => $labels[$type] ?? $type, 'value' => (string) $n],
                array_keys($byType),
                $byType,
            ),
        ];
    }

    /** @param EventParticipation[] $parts */
    private function topArtist(array $parts): ?array
    {
        $counts = [];
        $images = [];
        foreach ($parts as $p) {
            $name = $p->getEvent()->getArtistName();
            if ($name === null || trim($name) === '') {
                continue;
            }
            $key = mb_strtolower(trim($name));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $images[$key] ??= ['name' => trim($name), 'image' => null];
            $images[$key]['image'] ??= $p->getEvent()->getArtistImageUrl();
        }
        if ($counts === []) {
            return null;
        }

        arsort($counts);
        $key = (string) array_key_first($counts);
        $n = $counts[$key];

        return [
            'key' => 'top_artist',
            'kind' => 'hero',
            'eyebrow' => 'Ton artiste de l\'année',
            'title' => $images[$key]['name'],
            'subtitle' => $n > 1 ? 'Vu ' . $n . ' fois' : 'Vu une fois',
            'image' => $images[$key]['image'],
        ];
    }

    /** @param EventParticipation[] $parts */
    private function artistList(array $parts): ?array
    {
        $counts = [];
        $names = [];
        foreach ($parts as $p) {
            $name = $p->getEvent()->getArtistName();
            if ($name === null || trim($name) === '') {
                continue;
            }
            $key = mb_strtolower(trim($name));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $names[$key] ??= trim($name);
        }

        // À une seule entrée, le classement n'apprend rien de plus que la diapo précédente
        if (count($counts) < 3) {
            return null;
        }

        arsort($counts);
        $top = array_slice($counts, 0, 5, true);

        $items = [];
        $rank = 1;
        foreach ($top as $key => $n) {
            $items[] = ['label' => $names[$key], 'value' => (string) $n, 'rank' => $rank++];
        }

        return [
            'key' => 'artists',
            'kind' => 'list',
            'eyebrow' => 'Ton podium',
            'title' => count($counts) . ' ' . $this->plural(count($counts), 'artiste'),
            'subtitle' => 'vus cette année',
            'items' => $items,
        ];
    }

    /** @param EventParticipation[] $parts */
    private function hours(array $parts): ?array
    {
        $minutes = 0;
        foreach ($parts as $p) {
            $minutes += $p->getDuration() ?? 0;
        }
        if ($minutes < 60) {
            return null;
        }

        return [
            'key' => 'hours',
            'kind' => 'number',
            'eyebrow' => 'Debout dans la fosse',
            'title' => (string) (int) round($minutes / 60),
            'subtitle' => 'heures de live',
            'note' => $this->hoursNote($minutes),
        ];
    }

    private function hoursNote(int $minutes): string
    {
        $hours = $minutes / 60;

        return match (true) {
            $hours >= 48 => 'Soit ' . round($hours / 24, 1) . ' jours non-stop.',
            $hours >= 10 => 'De quoi traverser pas mal de fosses.',
            default => 'Chaque minute comptait.',
        };
    }

    /** @param EventParticipation[] $parts */
    private function topVenue(array $parts): ?array
    {
        $counts = [];
        foreach ($parts as $p) {
            if ($venue = $p->getEvent()->getVenue()) {
                $counts[$venue->getName()] = ($counts[$venue->getName()] ?? 0) + 1;
            }
        }
        if ($counts === []) {
            return null;
        }

        arsort($counts);
        $name = (string) array_key_first($counts);
        $n = $counts[$name];

        return [
            'key' => 'venue',
            'kind' => 'number',
            'eyebrow' => 'Ton quartier général',
            'title' => $name,
            'subtitle' => $n > 1 ? $n . ' soirées ici' : 'Une soirée ici',
            'note' => count($counts) > 1 ? count($counts) . ' lieux différents en tout' : null,
            'compact' => true,
        ];
    }

    /** @param EventParticipation[] $parts */
    private function topCompanion(array $parts): ?array
    {
        $counts = [];
        foreach ($parts as $p) {
            foreach ($p->getFriends() as $f) {
                $name = $f['displayName'] ?? $f['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    $counts[$name] = ($counts[$name] ?? 0) + 1;
                }
            }
        }
        if ($counts === []) {
            return null;
        }

        arsort($counts);
        $name = (string) array_key_first($counts);
        $n = $counts[$name];

        return [
            'key' => 'companion',
            'kind' => 'number',
            'eyebrow' => 'Ton acolyte',
            'title' => $name,
            'subtitle' => $n > 1 ? $n . ' sorties ensemble' : 'Une sortie ensemble',
            'compact' => true,
        ];
    }

    /** @param EventParticipation[] $parts */
    private function busiestMonth(array $parts): ?array
    {
        $counts = [];
        foreach ($parts as $p) {
            $m = (int) $p->getEvent()->getDate()->format('n');
            $counts[$m] = ($counts[$m] ?? 0) + 1;
        }
        arsort($counts);
        $month = (int) array_key_first($counts);
        $n = $counts[$month];

        // Un mois « le plus chargé » à un seul événement ne veut rien dire
        if ($n < 2) {
            return null;
        }

        return [
            'key' => 'month',
            'kind' => 'number',
            'eyebrow' => 'Ton mois le plus chargé',
            'title' => ucfirst(self::MONTHS_FR[$month]),
            'subtitle' => $n . ' ' . $this->plural($n, 'événement'),
            'compact' => true,
        ];
    }

    /** @param EventParticipation[] $parts */
    private function ratings(array $parts): ?array
    {
        $rated = array_values(array_filter($parts, fn (EventParticipation $p) => $p->getRating() !== null));
        if (count($rated) < 2) {
            return null;
        }

        $sum = array_sum(array_map(fn (EventParticipation $p) => $p->getRating(), $rated));

        $slide = [
            'key' => 'ratings',
            'kind' => 'number',
            'eyebrow' => 'Ta note moyenne',
            'title' => number_format($sum / count($rated), 1, ',', ' '),
            'subtitle' => 'sur 5 — ' . count($rated) . ' ' . $this->plural(count($rated), 'fiche') . ' remplie' . (count($rated) > 1 ? 's' : ''),
        ];

        if ($loved = $this->favourites($rated)) {
            $slide['prose_label'] = count($loved) > 1
                ? 'Tes ' . count($loved) . ' coups de cœur'
                : 'Ton coup de cœur';
            $slide['prose'] = $this->sentence($loved);
        }

        return $slide;
    }

    /**
     * Les 5 étoiles, du plus récent au plus ancien, sans doublon : quelqu'un qui
     * a vu Ultra Vomit trois fois et l'a noté 5 à chaque fois ne veut pas le lire
     * trois fois de suite.
     *
     * @param EventParticipation[] $rated
     *
     * @return list<string>
     */
    private function favourites(array $rated): array
    {
        $loved = array_filter($rated, fn (EventParticipation $p) => $p->getRating() === self::TOP_RATING);
        usort($loved, fn ($a, $b) => $b->getEvent()->getDate() <=> $a->getEvent()->getDate());

        $names = [];
        foreach ($loved as $p) {
            $name = $this->eventName($p);
            $names[mb_strtolower($name)] ??= $name;
        }

        return array_values($names);
    }

    /**
     * « A, B et C », ou « A, B, C et 12 autres » une fois le budget dépassé.
     *
     * @param list<string> $names
     */
    private function sentence(array $names): string
    {
        $kept = [];
        $length = 0;
        foreach ($names as $name) {
            $cost = mb_strlen($name) + 2; // le nom, plus « , »
            if ($kept !== [] && $length + $cost > self::PROSE_MAX_CHARS) {
                break;
            }
            $kept[] = $name;
            $length += $cost;
        }

        $extra = count($names) - count($kept);
        if ($extra > 0) {
            return implode(', ', $kept) . ' et ' . $extra . ' autres';
        }

        if (count($kept) === 1) {
            return $kept[0];
        }

        $last = array_pop($kept);

        return implode(', ', $kept) . ' et ' . $last;
    }

    /** @param EventParticipation[] $parts */
    private function topSong(array $parts): ?array
    {
        $counts = [];
        $labels = [];
        foreach ($parts as $p) {
            $event = $p->getEvent();
            $artist = trim((string) $event->getArtistName());
            if ($artist === '') {
                continue;
            }

            $songs = [...$event->getSetlistNormalized(), ...$event->getSetlistEncoresNormalized()];
            foreach ($songs as $song) {
                // Les intros diffusées ne sont pas jouées sur scène
                if (($song['tape'] ?? false) === true) {
                    continue;
                }
                $name = trim((string) ($song['name'] ?? ''));
                if ($name === '' || $this->isFiller($name)) {
                    continue;
                }

                // Clé « artiste|morceau », comme les stats du profil : sans l'artiste,
                // les Drum Solo de tout le monde fusionnent et trustent le classement
                $key = mb_strtolower($artist) . '|' . mb_strtolower($name);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $labels[$key] ??= ['name' => $name, 'artist' => $artist];
            }
        }

        arsort($counts);
        $key = array_key_first($counts);
        if ($key === null || $counts[$key] < 2) {
            return null;
        }

        return [
            'key' => 'song',
            'kind' => 'number',
            'eyebrow' => 'Le morceau qui t\'a poursuivi',
            'title' => $labels[$key]['name'],
            'subtitle' => 'Entendu ' . $counts[$key] . ' fois en live',
            'note' => $labels[$key]['artist'],
            'compact' => true,
        ];
    }

    /**
     * Les entrées de setlist qui ne sont pas des morceaux. setlist.fm les note
     * comme des titres ordinaires ; sans ce filtre, « Drum Solo » finit
     * « morceau de l'année » alors que personne ne l'a jamais fredonné.
     */
    private function isFiller(string $name): bool
    {
        $n = mb_strtolower(trim($name));

        foreach (['solo', 'intro', 'outro', 'jam', 'medley', 'interlude'] as $word) {
            if ($n === $word || str_ends_with($n, ' ' . $word) || str_starts_with($n, $word . ' ')) {
                return true;
            }
        }

        return false;
    }

    /** @param EventParticipation[] $parts */
    private function outro(int $year, array $parts): array
    {
        return [
            'key' => 'outro',
            'kind' => 'outro',
            'eyebrow' => 'Rewind ' . $year,
            'title' => 'À l\'année prochaine',
            'subtitle' => count($parts) . ' ' . $this->plural(count($parts), 'souvenir') . ' de plus',
            // La sortie revient au violet de marque, comme l'ouverture
            'accent' => '#B060FF',
        ];
    }

    private function eventName(EventParticipation $p): string
    {
        $e = $p->getEvent();

        return $e->getArtistName() ?? $e->getTournamentName() ?? $e->getTeams() ?? 'un événement';
    }

    private function plural(int $n, string $word): string
    {
        return $n > 1 ? $word . 's' : $word;
    }
}
