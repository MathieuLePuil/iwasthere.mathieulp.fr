<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Socle des contrôleurs derrière le pare-feu : getUser() est typé UserInterface|null
 * alors que ROLE_USER garantit un App\Entity\User. user() dit vrai au type, et
 * lève si jamais la garantie manquait — plutôt qu'un appel de méthode sur null.
 */
abstract class AppController extends AbstractController
{
    protected function user(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
