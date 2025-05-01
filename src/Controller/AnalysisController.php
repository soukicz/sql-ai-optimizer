<?php

namespace Soukicz\SqlAiOptimizer\Controller;

use Dibi\Helpers;
use GuzzleHttp\Promise\Each;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\MarkdownFormatter;
use Soukicz\Llm\Message\LLMMessageReasoning;
use Soukicz\Llm\Message\LLMMessageText;
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
        private UrlGeneratorInterface $router,
        private MarkdownFormatter $markdownFormatter
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

    #[Route('/run/{id}/continue', name: 'run.continue', methods: ['POST'])]
    public function continuePrompt(Request $request, int $id): Response {
        $queryData = $this->stateDatabase->getQuery($id);
        $run = $this->stateDatabase->getRun($queryData['run_id']);
        /** @var \Soukicz\Llm\LLMResponse $response */
        $response = $this->queryAnalyzer->continueConversation(
            conversation: LLMConversation::fromJson(json_decode($queryData['llm_conversation'], true)),
            prompt: $request->request->get('input'),
            useDatabaseAccess: $run['use_database_access']
        )->wait();

        $this->stateDatabase->updateConversation(
            queryId: $id,
            conversation: $response->getConversation(),
            conversationMarkdown: $this->markdownFormatter->responseToMarkdown($response)
        );

        return new JsonResponse([
            'url' => $this->router->generate('query.detail', ['queryId' => $id]),
        ]);
    }

    #[Route('/query/{queryId}', name: 'query.detail')]
    public function queryDetail(int $queryId, Request $request): Response {
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
                    if ($content instanceof LLMMessageText) {
                        $messages[] = [
                            'role' => 'user',
                            'content' => nl2br(htmlspecialchars($content->getText(), ENT_QUOTES)),
                        ];
                    }
                }
            } else {
                $onlyText = true;

                foreach ($message->getContents() as $content) {
                    if ($content instanceof LLMMessageReasoning) {
                        continue;
                    }

                    if (!($content instanceof LLMMessageText)) {
                        $onlyText = false;
                        break;
                    }
                }
                if ($onlyText) {
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

        $isExport = $request->query->has('export');
        $templateVars = [
            'query' => $query,
            'group' => $group,
            'sql' => $sql,
            'messages' => $messages,
            'backToRunUrl' => $this->router->generate('run.detail', ['id' => $query['run_id']]),
            'continueConversationUrl' => $this->router->generate('run.continue', ['id' => $queryId]),
            'exportUrl' => $this->router->generate('query.detail', ['queryId' => $queryId, 'export' => 1]),
        ];

        if ($isExport) {
            $templateVars['export'] = true;

            // Pass zip_export flag if present
            if ($request->query->has('zip_export')) {
                $templateVars['zip_export'] = true;
            }

            $content = $this->twig->render('analysis.html.twig', $templateVars);

            // Only set content disposition for direct download requests, not for ZIP exports
            if (!$request->query->has('zip_export')) {
                $response = new Response($content);
                $response->headers->set('Content-Type', 'text/html');
                $response->headers->set('Content-Disposition', 'attachment; filename="query-' . $queryId . '-export.html"');

                return $response;
            }

            return new Response($content);
        }

        return new Response($this->twig->render('analysis.html.twig', $templateVars));
    }
}
