<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EventWatchRepository;
use App\Ticketmaster\WatchChannel;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Une veille : un utilisateur suit un événement Ticketmaster pour être prévenu
 * avant l'ouverture de sa billetterie (J-1, puis H-1).
 *
 * `active` passe à faux quand l'événement est annulé — la veille reste visible
 * dans la liste, avec la raison, mais n'émet plus rien. Une veille supprimée
 * est effacée (cascade sur ses échéances).
 */
#[ORM\Entity(repositoryClass: EventWatchRepository::class)]
#[ORM\Table(name: 'event_watch')]
#[ORM\UniqueConstraint(name: 'uq_event_watch_user_event', columns: ['user_id', 'event_id'])]
#[ORM\Index(name: 'idx_event_watch_active', columns: ['active'])]
class EventWatch
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: TmEvent::class)]
    #[ORM\JoinColumn(name: 'event_id', referencedColumnName: 'event_id', nullable: false, onDelete: 'CASCADE', options: ['collation' => 'utf8mb4_bin'])]
    private TmEvent $event;

    #[ORM\Column(options: ['default' => true])]
    private bool $notifyJ1 = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $notifyH1 = true;

    #[ORM\Column(length: 10, enumType: WatchChannel::class)]
    private WatchChannel $channel = WatchChannel::Push;

    #[ORM\Column(type: 'datetime_utc_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function __construct(User $user, TmEvent $event)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->event = $event;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEvent(): TmEvent
    {
        return $this->event;
    }

    public function isNotifyJ1(): bool
    {
        return $this->notifyJ1;
    }

    public function setNotifyJ1(bool $notifyJ1): static
    {
        $this->notifyJ1 = $notifyJ1;

        return $this;
    }

    public function isNotifyH1(): bool
    {
        return $this->notifyH1;
    }

    public function setNotifyH1(bool $notifyH1): static
    {
        $this->notifyH1 = $notifyH1;

        return $this;
    }

    public function getChannel(): WatchChannel
    {
        return $this->channel;
    }

    public function setChannel(WatchChannel $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }
}
