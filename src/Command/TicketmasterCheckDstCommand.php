<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TmSaleWindowRepository;
use App\Ticketmaster\ParisTime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le contrôle de cohérence des horaires autour d'un changement d'heure
 * (25 octobre 2026 : passage à l'heure d'hiver).
 *
 * Le flux a déjà montré une faiblesse de conversion de fuseau sur un champ
 * voisin (`apiOnsaleStartDateTime`, +1 h partout). Si `onsaleStartDateTime`
 * souffrait du même mal après le changement d'heure, les ouvertures — très
 * majoritairement à des heures rondes, 10:00 en tête — apparaîtraient à 9:00
 * ou 11:00 en heure de Paris. On compare donc la répartition des heures
 * d'ouverture (heure de Paris) sur la semaine qui précède et celle qui suit.
 */
#[AsCommand(
    name: 'app:ticketmaster:check-dst',
    description: 'Compare la répartition des heures d\'ouverture (heure de Paris) avant et après un changement d\'heure',
)]
class TicketmasterCheckDstCommand extends Command
{
    public function __construct(private readonly TmSaleWindowRepository $windows)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', 'd', InputOption::VALUE_REQUIRED, 'Date du changement d\'heure (AAAA-MM-JJ)', '2026-10-25')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Largeur de chaque fenêtre de comparaison, en jours', '7')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'Lister les ouvertures de la semaine suivante, en UTC et en heure de Paris');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pivot = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input->getOption('date'), ParisTime::zone());
        if ($pivot === false) {
            $io->error('--date attend une date au format AAAA-MM-JJ.');

            return Command::INVALID;
        }
        $days = max(1, (int) $input->getOption('days'));
        $pivotUtc = $pivot->setTimezone(new \DateTimeZone('UTC'));

        $before = $this->windows->findStartingBetween($pivotUtc->modify("-{$days} days"), $pivotUtc, 5000);
        $after = $this->windows->findStartingBetween($pivotUtc, $pivotUtc->modify("+{$days} days"), 5000);

        $io->section(sprintf('Heures d\'ouverture (Paris), %d jours avant / après le %s', $days, $pivot->format('d/m/Y')));
        $rows = [];
        $histBefore = $this->histogram($before);
        $histAfter = $this->histogram($after);
        for ($h = 0; $h < 24; $h++) {
            if (($histBefore[$h] ?? 0) === 0 && ($histAfter[$h] ?? 0) === 0) {
                continue;
            }
            $rows[] = [sprintf('%02dh', $h), $histBefore[$h] ?? 0, $histAfter[$h] ?? 0];
        }
        $io->table(['Heure', 'Avant', 'Après'], $rows);
        $io->text(sprintf('%d ouvertures avant, %d après. Une bascule 10h → 9h ou 11h après la date signalerait un décalage du flux.', count($before), count($after)));

        if ($input->getOption('list')) {
            $io->section('Ouvertures de la période suivante');
            foreach ($after as $window) {
                $io->writeln(sprintf(
                    '  %s UTC → %s  %s',
                    $window->getStartsAtUtc()->format('Y-m-d H:i'),
                    ParisTime::dayAndTime($window->getStartsAtUtc(), $pivot),
                    $window->getEvent()->getName(),
                ));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<\App\Entity\TmSaleWindow> $windows
     *
     * @return array<int, int> heure de Paris → nombre d'ouvertures
     */
    private function histogram(array $windows): array
    {
        $hist = [];
        foreach ($windows as $window) {
            $hour = (int) ParisTime::toParis($window->getStartsAtUtc())->format('G');
            $hist[$hour] = ($hist[$hour] ?? 0) + 1;
        }
        ksort($hist);

        return $hist;
    }
}
