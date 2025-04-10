<?php

namespace Soukicz\SqlAiOptimizer;

use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\Client\LLMClient;
use Soukicz\Llm\Config\ReasoningBudget;
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
        private LLMClient $llmClient,
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

        $prompt = <<<EOT
                I need help to optimize my SQL queries on MySQL 8 server. I will provide tool to query perfomance schema and get specific queries to optimize.

                Query optimization can be achieved from different perspectives like execution time, memory usage, IOPS usage, etc. You must multiple optimization types and request query candicates with different queries to performance schema.

                After examinig each group, submit your selection of queries for this group using tool "submit_selection". I am expectiong to get at least four groups with 20 queries each.
        EOT;

        if (!empty($specialInstrutions)) {
            $prompt .= "\n\n**Special instructions:**\n\n" . $specialInstrutions;
        }

        $request = new LLMRequest(
            model: AnthropicClient::MODEL_SONNET_37_20250219,
            conversation: new LLMConversation([
                LLMMessage::createFromUser([new LLMMessageText($prompt)]),
                ]),
            tools: $tools,
            temperature: 1.0,
            maxTokens: 30_000,
            reasoningConfig: new ReasoningBudget(5000)
        );

        $response = $this->llmChainClient->run(
            client: $this->llmClient,
            request: $request,
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
