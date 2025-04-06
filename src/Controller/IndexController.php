<?php

namespace Soukicz\SqlAiOptimizer\Controller;

use Dibi\Helpers;
use Soukicz\SqlAiOptimizer\AI;
use Soukicz\SqlAiOptimizer\AnalyzedDatabase;
use Soukicz\SqlAiOptimizer\StateDatabase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;

class IndexController {
    public function __construct(
        private AnalyzedDatabase $database,
        private AI $ai,
        private Environment $twig,
        private StateDatabase $stateDatabase,
        private UrlGeneratorInterface $router
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

    #[Route('/run/{id}', name: 'run.detail')]
    public function runDetail(int $id): Response {
        $run = $this->stateDatabase->getRun($id);

        if (!$run) {
            return new RedirectResponse($this->router->generate('index'));
        }

        $groups = $this->stateDatabase->getGroupsByRunId($id);
        $queries = $this->stateDatabase->getQueriesByRunId($id);
        $queries = array_map(function ($query) {
            $query = (array)$query;
            $query['query_text_formatted'] = Helpers::dump($query['query_text'], true);
            $query['query_text_formatted'] = preg_replace('/^<pre[^>]*>|<\/pre>$/', '', $query['query_text_formatted']);

            return $query;
        }, $queries);

        return new Response(
            $this->twig->render('run_detail.html.twig', [
                'run' => $run,
                'groups' => $groups,
                'queries' => $queries,
            ])
        );
    }

    #[Route('/new-run', name: 'run.new', methods: ['POST'])]
    public function newRun(Request $request): Response {
        $results = $this->ai->getCandidateQueries();

        $this->stateDatabase->getConnection()->begin();
        $runId = $this->stateDatabase->createRun($request->request->get('input'), $results->getDescription());

        foreach ($results->getGroups() as $group) {
            $groupId = $this->stateDatabase->createGroup($runId, $group->getName(), $group->getDescription());

            foreach ($group->getQueries() as $query) {
                $this->stateDatabase->createQuery(
                    runId: $runId,
                    groupId: $groupId,
                    digest: $query->getDigest(),
                    queryText: $query->getQueryText(),
                    schema: $query->getSchema(),
                    impactDescription: $query->getImpactDescription()
                );
            }
        }

        $this->stateDatabase->getConnection()->commit();

        return new JsonResponse([
            'url' => '/run/' . $runId,
        ]);
    }

    #[Route('/submit-prompt', name: 'submit_prompt', methods: ['POST'])]
    public function submitPrompt(Request $request): Response {
        $prompt = $request->request->get('prompt');

        if (empty($prompt)) {
            // If no prompt provided, redirect back to index
            return new RedirectResponse($this->router->generate('index'));
        }

        // Create a new run
        $runId = $this->stateDatabase->createRun();

        // Generate candidate queries based on the prompt
        $this->ai->generateCandidateQueries($prompt, $runId);

        // Redirect to run detail
        return new RedirectResponse($this->router->generate('run_detail', ['id' => $runId]));
    }

    #[Route('/query/{id}', name: 'query_analysis')]
    public function queryAnalysis(int $id): Response {
        $query = $this->stateDatabase->getQuery($id);

        if (!$query) {
            // Handle non-existent query, redirect to index
            return new RedirectResponse($this->router->generate('index'));
        }

        $recommendations = $this->ai->getQueryRecommendations($id);

        return new Response(
            $this->twig->render('analysis.html.twig', [
                'query' => $query,
                'recommendations' => $recommendations,
            ])
        );
    }
}
