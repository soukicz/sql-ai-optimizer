<?php

namespace Soukicz\SqlAiOptimizer\Controller;

use Dibi\Helpers;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use Soukicz\SqlAiOptimizer\QueryAnalyzer;
use Soukicz\SqlAiOptimizer\QuerySelector;
use Soukicz\SqlAiOptimizer\AnalyzedDatabase;
use Soukicz\SqlAiOptimizer\Result\CandidateQuery;
use Soukicz\SqlAiOptimizer\StateDatabase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use GuzzleHttp\Promise\Each;
use League\CommonMark\Environment\Environment as CommonMarkEnvironment;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;

class IndexController {
    public function __construct(
        private AnalyzedDatabase $analyzedDatabase,
        private QuerySelector $querySelector,
        private QueryAnalyzer $queryAnalyzer,
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
        $results = $this->querySelector->getCandidateQueries();

        $this->stateDatabase->getConnection()->begin();
        $runId = $this->stateDatabase->createRun($request->request->get('input'), $results->getDescription());

        foreach ($results->getGroups() as $group) {
            $groupId = $this->stateDatabase->createGroup($runId, $group->getName(), $group->getDescription());

            foreach ($group->getQueries() as $query) {
                $rawSql = $this->analyzedDatabase->getQueryText($query->getDigest(), $query->getSchema());
                $this->stateDatabase->createQuery(
                    runId: $runId,
                    groupId: $groupId,
                    digest: $query->getDigest(),
                    queryText: $query->getQueryText(),
                    querySample: $rawSql,
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

            $promises[] = $this->queryAnalyzer->analyzeQuery((int)$queryId, $queryObject);
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
