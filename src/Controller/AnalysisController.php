<?php

namespace Soukicz\SqlAiOptimizer\Controller;

use Dibi\Helpers;
use GuzzleHttp\Promise\Each;
use Soukicz\Llm\LLMConversation;
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
                normalizedQuery: $queryData['normalized_query'],
                impactDescription: $queryData['impact_description'],
            );

            $promises[] = $this->queryAnalyzer->analyzeQuery((int)$queryId, $queryData['real_query'], $queryObject, $run['use_real_query'], $run['use_database_access']);
        }

        // Process 5 promises concurrently
        Each::ofLimit($promises, 5)->wait();

        return new JsonResponse([
            'url' => $this->router->generate('query.detail', ['queryId' => $queryIds[0]]),
        ]);
    }

    #[Route('/query/{queryId}', name: 'query.detail')]
    public function queryDetail(int $queryId): Response {
        $query = $this->stateDatabase->getQuery($queryId);
        if (!$query) {
            throw new \Exception('Query not found');
        }

        $group = $this->stateDatabase->getGroup($query['group_id']);

        $conversation = LLMConversation::fromJson(json_decode($query['llm_conversation'], true));

        $messages = [];
        $firstUser = true;
        foreach ($conversation->getMessages() as $message) {
            if (!$message->isAssistant() && !$message->isUser()) {
                continue;
            }
            if ($message->isUser() && $firstUser) {
                $firstUser = false;
                continue;
            }

            if ($message->isUser()) {
                foreach ($message->getContents() as $content) {
                    $messages[] = [
                        'role' => 'user',
                        'content' => nl2br(htmlspecialchars($content->getText(), ENT_QUOTES)),
                    ];
                }
            } else {
                $onlyText = true;
                foreach ($message->getContents() as $content) {
                    if (!$content instanceof LLMMessageText) {
                        $onlyText = false;
                        break;
                    }
                }
                if ($onlyText) {
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => nl2br(htmlspecialchars($message->getText(), ENT_QUOTES)),
                    ];
                } else {
                    foreach ($message->getContents() as $content) {
                        if ($content instanceof LLMMessageText) {
                            $messages[] = [
                                'role' => 'assistant',
                                'content' => $this->renderMarkdownWithHighlighting($content->getText()),
                            ];
                        }
                    }
                }
            }
        }

        if (empty($query['real_query'])) {
            $sql = Helpers::dump($query['normalized_query'], true);
        } else {
            $sql = Helpers::dump($query['real_query'], true);
        }
        $sql = preg_replace('/^<pre[^>]*>|<\/pre>$/', '', $sql);

        return new Response($this->twig->render('analysis.html.twig', [
            'query' => $query,
            'group' => $group,
            'sql' => $sql,
            'messages' => $messages,
            'backToRunUrl' => $this->router->generate('run.detail', ['id' => $query['run_id']]),
        ]));
    }
}
