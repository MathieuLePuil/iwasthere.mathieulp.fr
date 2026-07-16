<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EventParticipation;
use App\Reaction\ReactionEmoji;
use App\Reaction\ReactionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ReactionController extends AbstractController
{
    /**
     * Bascule une réaction. Appelé en fetch par le contrôleur Stimulus `reaction`,
     * qui a déjà repeint le bouton : la réponse porte l'état réel, à lui de se
     * corriger si les deux divergent.
     */
    #[Route('/reaction/{id}/toggle', name: 'app_reaction_toggle', methods: ['POST'])]
    public function toggle(
        EventParticipation $participation,
        Request $request,
        ReactionService $reactions,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('reaction', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        // La saisie est libre : tout ce qui n'est pas un emoji est refusé ici, c'est
        // le seul endroit qui le vérifie avant la base.
        $emoji = ReactionEmoji::normalize((string) $request->request->get('emoji'));
        if ($emoji === null) {
            return $this->json(['ok' => false, 'error' => 'emoji'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();
        if (!$reactions->canReact($user, $participation)) {
            throw $this->createAccessDeniedException();
        }

        $state = $reactions->toggle($user, $participation, $emoji);

        // Le fragment est rendu ici plutôt que reconstruit en JS : les pastilles
        // apparaissent et disparaissent au gré des emojis choisis, dupliquer leur
        // balisage côté client ferait deux vérités à tenir.
        return $this->json([
            'ok' => true,
            'html' => $this->renderView('components/_reaction_pills.html.twig', [
                'p' => $participation,
                'state' => $state,
            ]),
        ]);
    }
}
