<?php

namespace Soukicz\SqlAiOptimizer\Controller;

use Dibi\Helpers;
use GuzzleHttp\Promise\Each;
use League\CommonMark\Environment\Environment as CommonMarkEnvironment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;
use Soukicz\SqlAiOptimizer\QueryAnalyzer;
use Soukicz\SqlAiOptimizer\Result\CandidateQuery;
use Soukicz\SqlAiOptimizer\StateDatabase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

class AnalysisController {
    public function __construct(
        private QueryAnalyzer $queryAnalyzer,
        private Environment $twig,
        private StateDatabase $stateDatabase
    ) {
    }

    #[Route('/run/1/analyze', name: 'run.analyze', methods: ['POST'])]
    public function analyzePrompt(Request $request): Response {
        $queryIds = $request->request->all('query_ids');

        $promises = [];
        foreach ($queryIds as $queryId) {
            $queryData = $this->stateDatabase->getQuery($queryId);
            $queryObject = new CandidateQuery(
                schema: $queryData['schema'],
                digest: $queryData['digest'],
                queryText: $queryData['query_text'],
                impactDescription: $queryData['impact_description'],
            );

            $promises[] = $this->queryAnalyzer->analyzeQuery((int)$queryId, $queryData['query_sample'], $queryObject);
        }

        // Process 5 promises concurrently
        Each::ofLimit($promises, 5)->wait();

        return new JsonResponse();
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
        ]));
    }

    /**
     * Renders markdown content to HTML with syntax highlighting for code blocks
     */
    private function renderMarkdownWithHighlighting(string $markdown): string {
        $environment = new CommonMarkEnvironment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        // Add the core CommonMark rules
        $environment->addExtension(new CommonMarkCoreExtension());

        // Add the GFM extensions
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new TaskListExtension());

        // Create the converter
        $converter = new MarkdownConverter($environment);

        // Convert markdown to HTML
        return $converter->convert($markdown)->getContent();
    }
}
