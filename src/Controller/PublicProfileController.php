<?php

declare(strict_types=1);

namespace App\Controller;

use App\Badge\BadgeService;
use App\Entity\User;
use App\Repository\EventParticipationRepository;
use App\Repository\FriendRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La page d'un compte, à une adresse que tout le monde peut ouvrir, connecté ou non.
 *
 * L'adresse existe toujours ; c'est le contenu qui dépend du compte :
 *
 *  - public                    : les événements sont montrés à qui veut ;
 *  - privé, et on est l'ami    : montrés aussi — privé ne l'est jamais entre amis ;
 *  - privé, et on ne l'est pas : cadenas, et de quoi demander l'amitié.
 *
 * L'en-tête (pseudo, photo, bio, compteur) se voit dans tous les cas : c'est ce qui
 * permet de reconnaître la personne à qui l'on demande son amitié.
 *
 * Volontairement hors du préfixe /profile, qui est derrière le pare-feu et dont la
 * route /profile/{username} capterait de toute façon l'URL.
 */
class PublicProfileController extends AbstractController
{
    /** Ce que la page montre au maximum : un aperçu, pas l'historique complet. */
    private const EVENTS_SHOWN = 10;

    #[Route('/p/{username}', name: 'app_public_profile', requirements: ['username' => '[a-z0-9_]{3,30}'])]
    public function show(
        string $username,
        UserRepository $userRepo,
        FriendRepository $friendRepo,
        EventParticipationRepository $partRepo,
        BadgeService $badges,
    ): Response {
        $profileUser = $userRepo->findOneBy(['username' => $username]);
        if (!$profileUser) {
            throw $this->createNotFoundException();
        }

        $viewer = $this->getUser();
        $isSelf = $viewer instanceof User && $viewer->getId()->equals($profileUser->getId());
        $areFriends = $viewer instanceof User && !$isSelf && $friendRepo->areFriends($viewer, $profileUser);
        $canSee = $profileUser->isPublicProfile() || $isSelf || $areFriends;

        $response = $this->render('public/profile.html.twig', [
            'profile_user' => $profileUser,
            'can_see' => $canSee,
            'is_self' => $isSelf,
            'are_friends' => $areFriends,
            // La relation sert à savoir quoi proposer : demander, patienter, ou rien.
            'relationship' => $viewer instanceof User && !$isSelf
                ? $friendRepo->findRelationship($viewer, $profileUser)
                : null,
            // Le compteur reste affiché derrière le cadenas : il dit ce qu'on rate,
            // sans rien dire de ce qui a été vécu.
            'total_events' => $partRepo->countPastForProfile($profileUser),
            'events' => $canSee ? $partRepo->findRecentPastForProfile($profileUser, self::EVENTS_SHOWN) : [],
            'badges' => $canSee ? $badges->forUser($profileUser) : null,
        ]);

        // Un compte privé n'a rien à faire dans un moteur de recherche, même réduit à
        // son cadenas. Un compte public, si : c'est l'intérêt d'avoir une adresse.
        if (!$profileUser->isPublicProfile()) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
