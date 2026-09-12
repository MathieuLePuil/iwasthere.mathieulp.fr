<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\FetchArtistImageMessage;
use App\Repository\EventRepository;
use App\Service\DeezerArtistService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class FetchArtistImageMessageHandler
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly DeezerArtistService $deezer,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(FetchArtistImageMessage $message): void
    {
        $event = $this->eventRepository->find($message->eventId);
        if ($event === null || $event->getArtistImageUrl()) {
            return;
        }

        if ($this->deezer->applyToEvent($event)) {
            $this->em->flush();
        }
    }
}
