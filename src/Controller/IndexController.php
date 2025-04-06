<?php

namespace Soukicz\SqlAiOptimizer\Controller;

use Soukicz\SqlAiOptimizer\StateDatabase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

class IndexController {
    public function __construct(
        private Environment $twig,
        private StateDatabase $stateDatabase
    ) {
    }

    #[Route('/', name: 'index')]
    public function index(): Response {
        $runs = $this->stateDatabase->getRuns();

        return new Response(
            $this->twig->render('runs.html.twig', [
                'runs' => $runs,
            ])
        );
    }
}
