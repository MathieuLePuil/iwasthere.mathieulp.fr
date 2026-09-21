<?php

declare(strict_types=1);

namespace App\Ticketmaster;

/**
 * Les dates telles qu'on les écrit à l'utilisateur : en heure de Paris, en
 * français, courtes. Toujours via DateTimeZone('Europe/Paris') — jamais un
 * décalage fixe, le passage à l'heure d'hiver tombe au milieu du catalogue.
 */
final class ParisTime
{
    private const DAYS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
    private const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone('Europe/Paris');
    }

    public static function toParis(\DateTimeInterface $utc): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($utc)->setTimezone(self::zone());
    }

    /** « sam. 3 oct. » (l'année n'est ajoutée que si elle diffère de l'année en cours) */
    public static function day(\DateTimeInterface $date, ?\DateTimeInterface $now = null): string
    {
        return self::dayLocal(self::toParis($date), $now);
    }

    /** Comme day(), pour une date déjà dans son fuseau (la date du concert, dans celui de la salle). */
    public static function dayLocal(\DateTimeImmutable $d, ?\DateTimeInterface $now = null): string
    {
        $label = sprintf('%s %d %s', self::DAYS[(int) $d->format('w')], (int) $d->format('j'), self::MONTHS[(int) $d->format('n') - 1]);
        $currentYear = self::toParis($now ?? new \DateTimeImmutable())->format('Y');
        if ($d->format('Y') !== $currentYear) {
            $label .= ' ' . $d->format('Y');
        }

        return $label;
    }

    /** « 10h00 » */
    public static function time(\DateTimeInterface $date): string
    {
        return self::toParis($date)->format('G\hi');
    }

    /** « sam. 3 oct. à 10h00 » */
    public static function dayAndTime(\DateTimeInterface $date, ?\DateTimeInterface $now = null): string
    {
        return self::day($date, $now) . ' à ' . self::time($date);
    }

    /** « dans 3 h », « dans 45 min » */
    public static function in(\DateTimeInterface $target, \DateTimeInterface $now): string
    {
        $seconds = max(0, $target->getTimestamp() - $now->getTimestamp());
        if ($seconds < 3600) {
            return sprintf('dans %d min', intdiv($seconds, 60));
        }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $minutes === 0 ? sprintf('dans %d h', $hours) : sprintf('dans %d h %02d', $hours, $minutes);
    }

    /** Minuit, heure de Paris, du jour courant — le début du « jour » du plafond — rendu en UTC pour la base. */
    public static function startOfDay(\DateTimeInterface $now): \DateTimeImmutable
    {
        return self::toParis($now)->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
    }
}
