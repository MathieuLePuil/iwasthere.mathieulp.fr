<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ImportSetlistMessage;
use App\Repository\EventRepository;
use App\Service\SetlistFmService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ImportSetlistMessageHandler
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly SetlistFmService $setlistFmService,
    ) {}

    public function __invoke(ImportSetlistMessage $message): void
    {
        $event = $this->eventRepository->find($message->eventId);
        if (!$event) {
            return;
        }

        $this->setlistFmService->tryImportSetlist($event);
    }
}
