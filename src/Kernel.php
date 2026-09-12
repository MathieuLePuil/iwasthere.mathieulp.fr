<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Toute l'app raisonne en heure française : « aujourd'hui » dans les requêtes
     * (Event::isPast, les listes passé/à venir), l'heure des rappels, le salut de
     * l'accueil. Le serveur est en UTC ; sans ce réglage, entre minuit et deux
     * heures du matin un événement du jour était encore « demain » sur une page et
     * « aujourd'hui » sur une autre, selon que le code avait pensé au fuseau.
     */
    public function boot(): void
    {
        date_default_timezone_set('Europe/Paris');

        parent::boot();
    }
}
