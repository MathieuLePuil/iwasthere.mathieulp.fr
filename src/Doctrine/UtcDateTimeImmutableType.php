<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

/**
 * Un DATETIME stocké en UTC, quel que soit le fuseau PHP courant.
 *
 * Le type `datetime_immutable` de Doctrine écrit et lit l'heure « murale »
 * dans le fuseau par défaut — Europe/Paris ici, posé par Kernel::boot(). Ça
 * convient au journal (une date vécue en France) mais pas aux ouvertures de
 * billetterie Ticketmaster, exprimées en UTC et comparées à l'instant présent
 * par des crons : l'heure de Paris est ambiguë une heure par an (25 octobre
 * 2026, 02:00-03:00 se produit deux fois) et le flux a déjà montré une
 * faiblesse de conversion sur un champ voisin. Ici, la valeur est convertie
 * en UTC à l'écriture et relue en UTC — l'affichage passe ensuite par
 * DateTimeZone('Europe/Paris') (voir App\Ticketmaster\ParisTime).
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public const NAME = 'datetime_utc_immutable';

    private static ?\DateTimeZone $utc = null;

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            $value = \DateTimeImmutable::createFromInterface($value)->setTimezone(self::utc());
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if ($value === null || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        $converted = \DateTimeImmutable::createFromFormat($platform->getDateTimeFormatString(), (string) $value, self::utc());
        if ($converted === false) {
            throw InvalidFormat::new((string) $value, static::class, $platform->getDateTimeFormatString());
        }

        return $converted;
    }

    private static function utc(): \DateTimeZone
    {
        return self::$utc ??= new \DateTimeZone('UTC');
    }
}
