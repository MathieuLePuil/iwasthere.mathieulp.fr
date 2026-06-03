<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly NotificationRepository $notifRepo,
        private readonly Security $security,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications_count', $this->getUnreadCount(...)),
        ];
    }

    public function getUnreadCount(): int
    {
        if (!$user = $this->security->getUser()) {
            return 0;
        }
        return $this->notifRepo->countUnread($user);
    }
}
