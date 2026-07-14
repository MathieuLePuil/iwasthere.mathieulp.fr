<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\EventParticipationRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use App\Service\SetlistFmService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:notifications:send-reminders',
    description: 'Send post-event reminder notifications to users who have not rated their events',
)]
class SendEventRemindersCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepo,
        private readonly EventParticipationRepository $participationRepo,
        private readonly NotificationService $notificationService,
        private readonly SetlistFmService $setlistFmService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Les utilisateurs saisissent leur heure de rappel en heure française ; le serveur est en UTC
        $currentTime = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('H:i');

        $users = $this->userRepo->findBy(['notifCompletionEnabled' => true]);
        $io->info(sprintf('%d user(s) with completion reminders enabled', count($users)));

        $sent = 0;
        foreach ($users as $user) {
            $configuredTime = $user->getNotifCompletionTime() ?? '08:00';

            if ($configuredTime !== $currentTime) {
                continue;
            }

            // Sans cela, les participations restent "upcoming" tant que l'utilisateur
            // n'a pas ouvert l'app, et findPendingReminders ne les voit pas
            $this->participationRepo->updateStaleUpcoming($user);

            $reminders = $this->participationRepo->findPendingReminders($user);
            if (empty($reminders)) {
                continue;
            }

            $count = count($reminders);
            $title = 'Comment s\'était ce concert ?';
            $body = $count === 1
                ? 'Tu n\'as pas encore rempli ta fiche pour ' . ($reminders[0]->getEvent()->getArtistName() ?? 'ton dernier événement') . '.'
                : sprintf('%d événements attendent ta note et tes commentaires.', $count);
            $url = $count === 1
                ? '/event/' . $reminders[0]->getEvent()->getId() . '/edit'
                : '/';

            $this->notificationService->sendNotification($title, $body, (string) $user->getId(), $url);
            $io->writeln(sprintf('  → %s (%d event(s))', $user->getUsername(), $count));
            $sent++;

            foreach ($reminders as $reminder) {
                $event = $reminder->getEvent();
                if (!empty($event->getSetlist())) {
                    continue;
                }
                if ($this->setlistFmService->tryImportSetlist($event)) {
                    $io->writeln(sprintf('    ♪ Setlist importée : %s', $event->getArtistName()));
                }
                // Respect API rate limit (2 req/s on free plan)
                usleep(600_000);
            }
        }

        $io->success(sprintf('Reminders sent to %d user(s)', $sent));

        return Command::SUCCESS;
    }
}
