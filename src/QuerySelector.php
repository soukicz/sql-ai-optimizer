<?php

namespace Soukicz\SqlAiOptimizer;

use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\Anthropic\Model\AnthropicClaude37Sonnet;
use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\Client\OpenAI\Model\GPTo3;
use Soukicz\Llm\Client\OpenAI\OpenAIClient;
use Soukicz\Llm\Config\ReasoningBudget;
use Soukicz\Llm\Config\ReasoningEffort;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Tool\CallbackToolDefinition;
use Soukicz\SqlAiOptimizer\Result\CandidateQuery;
use Soukicz\SqlAiOptimizer\Result\CandidateQueryGroup;
use Soukicz\SqlAiOptimizer\Result\CandidateResult;
use Soukicz\SqlAiOptimizer\Tool\PerformanceSchemaQueryTool;

readonly class QuerySelector {
    public function __construct(
        private LLMChainClient $llmChainClient,
        private AnthropicClient $anthropicClient,
        private OpenAIClient $llmClient,
        private PerformanceSchemaQueryTool $performanceSchemaQueryTool
    ) {
    }

    public function getCandidateQueries(?string $specialInstrutions): CandidateResult {
        $groups = [];

        $tools = [
            $this->performanceSchemaQueryTool,
        ];

        $submitInputSchema = [
            'type' => 'object',
            'required' => ['queries', 'group_name', 'group_description'],
            'properties' => [
                'group_name' => [
                    'type' => 'string',
                    'description' => 'Group name',
                ],
                'group_description' => [
                    'type' => 'string',
                    'description' => 'Description of performance impact type of the group',
                ],
                'queries' => [
                    'type' => 'array',
                    'description' => 'Array of query digests to optimize (min 1, max 20)',
                    'minItems' => 1,
                    'maxItems' => 20,
                    'items' => [
                        'type' => 'object',
                        'required' => ['digest', 'query_sample', 'schema', 'reason'],
                        'properties' => [
                            'digest' => [
                                'type' => 'string',
                                'description' => 'The query digest hash from performance_schema',
                            ],
                            'query_sample' => [
                                'type' => 'string',
                                'description' => 'The query text from performance_schema',
                            ],
                            'schema' => [
                                'type' => 'string',
                                'description' => 'The database schema the query operates on',
                            ],
                            'reason' => [
                                'type' => 'string',
                                'description' => 'Explanation of why this query is worth optimizing - formulate it in a way that it will be obvious if mentioned numbers are about a single query or total for all queries in the group',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $tools[] = new CallbackToolDefinition(
            name: 'submit_selection',
            description: 'Submit your selection of 20 most expensive queries',
            inputSchema: $submitInputSchema,
            handler: function (array $input) use (&$groups): string {
                $groups[] = $input;

                return 'Selection submitted';
            }
        );

        $preparationPrompt = <<<EOT
        I have MySQL 8 database server and I need to find queries that are consuming a lot of resources (memory, CPU, IOPS, ...) and are best candidates for optimization.
        
        I will provide you with tool to run read-only queries against performance_schema later but you will first have to formulate plan how to best find queries for optimization from different perspectives.

        I am looking just for candidates for optimization, not for exact queries - I will need just query digests for now.
        EOT;

        if (!empty($specialInstrutions)) {
            $preparationPrompt .= "\n\n**Special instructions:**\n\n" . $specialInstrutions;
        }

        $preparationResponse = $this->llmChainClient->run(
            client: $this->llmClient,
            request: new LLMRequest(
                model: new GPTo3(GPTo3::VERSION_2025_04_16),
                conversation: new LLMConversation([
                    LLMMessage::createFromUser([new LLMMessageText($preparationPrompt)]),
                    ]),
                temperature: 1.0,
                maxTokens: 30_000,
                reasoningConfig: ReasoningEffort::HIGH
            ),
        );

        $toolName = $this->performanceSchemaQueryTool->getName();

        $prompt = <<<EOT
        This is great! I will now provide you with tool "$toolName" to run read-only queries against database.
        
        Your task is to identify query groups from different perspectives like execution time, memory usage, IOPS usage, etc (as you planned previously). 
        
        Examine each group and find best query candicates for optimization (which will by done later).

        After examinig each group, submit your selection of queries for this group using tool "submit_selection". I am expectiong to get at least four groups with at around 20 queries each.
        EOT;

        if (!empty($specialInstrutions)) {
            $prompt .= "\n\n**Special instructions:**\n\n" . $specialInstrutions;
        }

        $conversation = $preparationResponse->getConversation()
        ->withMessage(LLMMessage::createFromUser([new LLMMessageText($prompt)]));

        $response = $this->llmChainClient->run(
            client: $this->anthropicClient,
            request: new LLMRequest(
                model: new AnthropicClaude37Sonnet(AnthropicClaude37Sonnet::VERSION_20250219),
                conversation: $conversation,
                tools: $tools,
                temperature: 1.0,
                maxTokens: 50_000,
                reasoningConfig: new ReasoningBudget(20_000)
            ),
        );

        $resultGroups = [];
        foreach ($groups as $group) {
            $resultGroups[] = new CandidateQueryGroup(
                name: $group['group_name'],
                description: $group['group_description'],
                queries: array_map(fn (array $query) => new CandidateQuery(
                    schema: $query['schema'],
                    digest: $query['digest'],
                    normalizedQuery: $query['query_sample'],
                    impactDescription: $query['reason'],
                ), $group['queries']),
            );
        }

        return new CandidateResult(
            description: $response->getLastText(),
            groups: $resultGroups,
        );
    }
}
