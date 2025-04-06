<?php

namespace Soukicz\SqlAiOptimizer;

use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\Config\ReasoningBudget;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Llm\Tool\ToolDefinition;
use Soukicz\SqlAiOptimizer\Tool\PerfomanceSchemaQueryTool;

readonly class AI {
    public function __construct(
        private LLMChainClient $llmChainClient,
        private AnthropicClient $anthropicClient,
        private PerfomanceSchemaQueryTool $perfomanceSchemaQueryTool
    ) {
    }

    public function getCandidateQueries(): array {
        $results = null;

        $tools = [
            $this->perfomanceSchemaQueryTool,
        ];

        $tools[] = new ToolDefinition(
            name: 'submit_selection',
            description: 'Submit your selection of 20 most expensive queries',
            inputSchema: [
                'type' => 'object',
                'required' => ['queries'],
                'properties' => [
                    'group' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'Group name',
                            ],
                        ],
                        'desription' => [
                                'type' => 'string',
                                'description' => 'Description of performance impact type of the group',
                            ],
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
                                    'description' => 'Explanation of why this query is worth optimizing',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            handler: function (array $input) use (&$results): string {
                $results = $input['queries'];

                return 'Selection submitted';
            }
        );

        $request = new LLMRequest(
            model: AnthropicClient::MODEL_SONNET_37_20250219,
            conversation: new LLMConversation([
                LLMMessage::createFromUser([
                    new LLMMessageText(<<<EOT
                    I need help to optimize my SQL queries on MySQL 8 server. I will provide tool to query perfomance schema and get specific queries to optimize.

                    Query optimization can be achieved from different perspectives like execution time, memory usage, IOPS usage, etc. You must multiple optimization types and request query candicates with different queries to performance schema.

                    After examinig each group, submit your selection of queries for this group using tool "submit_selection". I am expectiong to get at least four groups with 20 queries each.
                    EOT),
                ]),
                ]),
            tools: $tools,
            temperature: 1.0,
            maxTokens: 30_000,
            reasoningConfig: new ReasoningBudget(5000)
        );

        $response = $this->llmChainClient->run(
            client: $this->anthropicClient,
            request: $request,
        );

        return $results;
    }
}
