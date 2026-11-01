<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\User;
use App\Notification\NotificationType;
use PHPUnit\Framework\TestCase;

final class NotificationTypeTest extends TestCase
{
    public function testEveryTypeBelongsToExactlyOneSettingsGroup(): void
    {
        $grouped = array_merge(...array_values(NotificationType::groups()));

        self::assertCount(count(NotificationType::cases()), $grouped);
        self::assertEqualsCanonicalizing(NotificationType::cases(), $grouped);
    }

    public function testPushIsWantedUnlessExplicitlyDisabled(): void
    {
        $user = new User();
        self::assertTrue($user->wantsPush(NotificationType::EventDay));

        $user->setNotifPrefs([NotificationType::EventDay->value => false]);
        self::assertFalse($user->wantsPush(NotificationType::EventDay));
        self::assertTrue($user->wantsPush(NotificationType::EventUpdated), 'un type absent reste activé');
    }

    public function testPrivacyDefaultsToFriends(): void
    {
        $user = new User();
        self::assertTrue($user->canBeSeenBy('events', false, true));
        self::assertFalse($user->canBeSeenBy('events', false, false));

        $user->setPrivacyLevel('events', 'private');
        self::assertFalse($user->canBeSeenBy('events', false, true));
        self::assertTrue($user->canBeSeenBy('events', true, false));

        $user->setPrivacyLevel('events', 'nimporte');
        self::assertSame('private', $user->getPrivacyLevel('events'), 'une valeur inconnue est ignorée');
    }
}
