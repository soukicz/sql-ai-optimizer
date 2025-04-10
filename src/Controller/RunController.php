<?php

namespace Soukicz\SqlAiOptimizer\Controller;

use Dibi\Helpers;
use Soukicz\SqlAiOptimizer\QuerySelector;
use Soukicz\SqlAiOptimizer\AnalyzedDatabase;
use Soukicz\SqlAiOptimizer\StateDatabase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Symfony\Component\HttpFoundation\RedirectResponse;

class RunController extends BaseController {
    public function __construct(
        private AnalyzedDatabase $analyzedDatabase,
        private QuerySelector $querySelector,
        private Environment $twig,
        private StateDatabase $stateDatabase,
        private UrlGeneratorInterface $router
    ) {
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
            $query['normalized_query_formatted'] = Helpers::dump($query['normalized_query'], true);
            $query['normalized_query_formatted'] = preg_replace('/^<pre[^>]*>|<\/pre>$/', '', $query['normalized_query_formatted']);

            return $query;
        }, $queries);

        $missingSqlCount = 0;
        foreach ($queries as $query) {
            if (empty($query['real_query'])) {
                $missingSqlCount++;
            }
        }

        $specialInstructions = $run['input'];
        if (!empty($specialInstructions)) {
            $specialInstructions = nl2br(htmlspecialchars($specialInstructions));
        }

        return new Response(
            $this->twig->render('run_detail.html.twig', [
                'summary' => $this->renderMarkdownWithHighlighting($run['output']),
                'run' => $run,
                'groups' => $groups,
                'queries' => $queries,
                'missingSqlCount' => $missingSqlCount,
                'specialInstructions' => $specialInstructions,
            ])
        );
    }

    #[Route('/new-run', name: 'run.new', methods: ['POST'])]
    public function newRun(Request $request): Response {
        $results = $this->querySelector->getCandidateQueries($request->request->get('input'));

        $useRealQuery = $request->request->getBoolean('use_real_query', false);

        $this->stateDatabase->getConnection()->begin();
        $runId = $this->stateDatabase->createRun(
            $request->request->get('input'),
            $this->analyzedDatabase->getHostnameWithPort(),
            $results->getDescription(),
            $useRealQuery,
            $request->request->getBoolean('use_database_access', false)
        );

        foreach ($results->getGroups() as $group) {
            $groupId = $this->stateDatabase->createGroup($runId, $group->getName(), $group->getDescription());

            foreach ($group->getQueries() as $query) {
                if (empty($query->getSchema()) || $query->getSchema() === 'NULL' || $query->getSchema() === 'unknown') {
                    continue;
                }

                $rawSql = $this->analyzedDatabase->getQueryText($query->getDigest(), $query->getSchema());

                $this->stateDatabase->createQuery(
                    runId: $runId,
                    groupId: $groupId,
                    digest: $query->getDigest(),
                    normalizedQuery: $query->getNormalizedQuery(),
                    realQuery: $rawSql,
                    schema: $query->getSchema(),
                    impactDescription: $query->getImpactDescription()
                );
            }
        }

        $this->stateDatabase->getConnection()->commit();

        return new JsonResponse([
            'url' => '/run/' . $runId . '#first',
        ]);
    }

    #[Route('/run/{id}/fetch-queries', name: 'run.fetch-queries')]
    public function runFetchQueries(int $id): Response {
        $run = $this->stateDatabase->getRun($id);
        if (!$run) {
            return new JsonResponse([
                'error' => 'Run not found',
            ], 404);
        }

        $digests = [];
        $queries = [];
        $totalQueriesCount = $this->stateDatabase->getQueriesCount($id);
        foreach ($this->stateDatabase->getQueriesWithoutRealQuery($id) as $query) {
            if (!isset($digests[$query['digest']])) {
                $digests[$query['digest']] = [];
            }

            $digests[$query['digest']][] = $query['id'];
            $queries[$query['id']] = $query['schema'];
        }

        if (!empty($digests)) {
            foreach ($this->analyzedDatabase->getQueryTexts(array_keys($digests)) as $sql) {
                if (isset($digests[$sql['digest']])) {
                    foreach ($digests[$sql['digest']] as $i => $id) {
                        if ($queries[$id] === $sql['current_schema']) {
                            $this->stateDatabase->setRealQuery($id, $sql['sql_text']);
                            unset($queries[$id]);
                            unset($digests[$sql['digest']][$i]);
                        }
                    }
                }
            }
        }

        return new JsonResponse([
            'totalQueriesCount' => $totalQueriesCount,
            'missingQueriesCount' => count($queries),
        ]);
    }
}
