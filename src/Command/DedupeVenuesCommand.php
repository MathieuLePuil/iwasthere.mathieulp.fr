<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:venues:dedupe',
    description: 'Merge duplicate venues (same normalized name) and repoint their events to a single canonical venue',
)]
class DedupeVenuesCommand extends Command
{
    /** French articles/prepositions ignored when comparing names ("Zénith de Paris" == "Zenith Paris"). */
    private const STOP_WORDS = ['de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'the'];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Actually apply the merge (otherwise dry-run only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('force');

        $venues = $this->em->getRepository(Venue::class)->findAll();

        // Group venues by their normalized name.
        /** @var array<string, Venue[]> $groups */
        $groups = [];
        foreach ($venues as $venue) {
            $groups[$this->normalize($venue->getName())][] = $venue;
        }

        $merged = 0;
        $eventsMoved = 0;

        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }

            $canonical = $this->pickCanonical($group);
            $duplicates = array_filter($group, static fn (Venue $v) => $v !== $canonical);

            $io->section(sprintf('« %s »  →  garde « %s »', $group[0]->getName(), $canonical->getName()));

            foreach ($duplicates as $dup) {
                $events = $this->em->getRepository(Event::class)->findBy(['venue' => $dup]);
                $io->writeln(sprintf('  - « %s » (%d événement·s) fusionné', $dup->getName(), count($events)));

                foreach ($events as $event) {
                    $event->setVenue($canonical);
                    $eventsMoved++;
                }

                if ($apply) {
                    $this->em->flush();          // repoint events before removing the venue (FK safety)
                    $this->em->remove($dup);
                    $this->em->flush();
                }
                $merged++;
            }
        }

        if ($merged === 0) {
            $io->success('Aucun doublon détecté.');

            return Command::SUCCESS;
        }

        if ($apply) {
            $io->success(sprintf('%d lieux fusionnés, %d événements repointés.', $merged, $eventsMoved));
        } else {
            $io->warning(sprintf(
                '%d lieux seraient fusionnés, %d événements repointés. Rien n\'a été modifié — relancez avec --force pour appliquer.',
                $merged,
                $eventsMoved,
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Canonical = the venue with the most events, then the most complete data,
     * then the longest (richest) name, then the oldest.
     *
     * @param Venue[] $group
     */
    private function pickCanonical(array $group): Venue
    {
        usort($group, function (Venue $a, Venue $b): int {
            // Higher event count / completeness / name length wins; older createdAt breaks final ties.
            return $this->eventCount($b) <=> $this->eventCount($a)
                ?: $this->completeness($b) <=> $this->completeness($a)
                ?: mb_strlen($b->getName()) <=> mb_strlen($a->getName())
                ?: $a->getCreatedAt() <=> $b->getCreatedAt();
        });

        return $group[0];
    }

    private function eventCount(Venue $venue): int
    {
        return \count($this->em->getRepository(Event::class)->findBy(['venue' => $venue]));
    }

    private function completeness(Venue $venue): int
    {
        return ($venue->getCity() !== '' ? 1 : 0)
            + ($venue->getAddress() !== '' ? 1 : 0)
            + (($venue->getLatitude() !== 0.0 || $venue->getLongitude() !== 0.0) ? 1 : 0);
    }

    private function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));

        // Strip accents.
        $name = strtr($name, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ñ' => 'n',
        ]);

        // Tokenize on anything non-alphanumeric and drop stop words.
        $tokens = array_filter(
            preg_split('/[^a-z0-9]+/', $name) ?: [],
            static fn (string $t): bool => $t !== '' && !\in_array($t, self::STOP_WORDS, true),
        );

        return implode(' ', $tokens);
    }
}
