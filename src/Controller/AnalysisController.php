<?php

namespace Soukicz\SqlAiOptimizer\Controller;

use Dibi\Helpers;
use GuzzleHttp\Promise\Each;
use Soukicz\SqlAiOptimizer\QueryAnalyzer;
use Soukicz\SqlAiOptimizer\Result\CandidateQuery;
use Soukicz\SqlAiOptimizer\StateDatabase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

class AnalysisController extends BaseController {
    public function __construct(
        private QueryAnalyzer $queryAnalyzer,
        private Environment $twig,
        private StateDatabase $stateDatabase,
        private UrlGeneratorInterface $router
    ) {
    }

    #[Route('/run/{id}/analyze', name: 'run.analyze', methods: ['POST'])]
    public function analyzePrompt(Request $request, int $id): Response {
        $queryIds = array_values($request->request->all('query_ids'));

        $run = $this->stateDatabase->getRun($id);
        if (!$run) {
            throw new \Exception('Run not found');
        }

        $promises = [];
        foreach ($queryIds as $queryId) {
            $queryData = $this->stateDatabase->getQuery($queryId);
            $queryObject = new CandidateQuery(
                schema: $queryData['schema'],
                digest: $queryData['digest'],
                queryText: $queryData['query_text'],
                impactDescription: $queryData['impact_description'],
            );

            $promises[] = $this->queryAnalyzer->analyzeQuery((int)$queryId, $queryData['query_sample'], $queryObject, $run['use_database_access']);
        }

        // Process 5 promises concurrently
        Each::ofLimit($promises, 5)->wait();

        return new JsonResponse([
            'url' => $this->router->generate('query.detail', ['id' => $queryIds[0]]),
        ]);
    }

    #[Route('/query/{queryId}', name: 'query.detail')]
    public function queryDetail(int $queryId): Response {
        $query = $this->stateDatabase->getQuery($queryId);
        if (!$query) {
            throw new \Exception('Query not found');
        }

        $group = $this->stateDatabase->getGroup($query['group_id']);

        $markdown = $query['fix_output'];

        // Convert markdown to HTML with syntax highlighting
        $html = '';
        if (!empty($markdown)) {
            $html = $this->renderMarkdownWithHighlighting($markdown);
        }

        if (empty($query['query_sample'])) {
            $sql = Helpers::dump($query['query_text'], true);
        } else {
            $sql = Helpers::dump($query['query_sample'], true);
        }
        $sql = preg_replace('/^<pre[^>]*>|<\/pre>$/', '', $sql);

        return new Response($this->twig->render('analysis.html.twig', [
            'query' => $query,
            'group' => $group,
            'sql' => $sql,
            'recommendations' => $html,
            'backToRunUrl' => $this->router->generate('run.detail', ['id' => $query['run_id']]),
        ]));
    }
}
