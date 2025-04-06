<?php

namespace Soukicz\SqlAiOptimizer\Controller;

use Soukicz\SqlAiOptimizer\AI;
use Soukicz\SqlAiOptimizer\AnalyzedDatabase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class IndexController {
    public function __construct(
        private AnalyzedDatabase $database,
        private AI $ai
    ) {
    }

    #[Route('/', name: 'index')]
    public function index(): Response {
        print_r($this->ai->getCandidateQueries());

        return new Response('OK');
    }
}
