<?php

namespace Soukicz\SqlAiOptimizer\Tool;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\Llm\Tool\ToolDefinition;
use Soukicz\Llm\Tool\ToolResponse;
use Soukicz\SqlAiOptimizer\Service\DatabaseQueryExecutor;

class PerformanceSchemaQueryTool extends ToolDefinition {
    public function __construct(
        private DatabaseQueryExecutor $queryExecutor
    ) {
    }

    public function getName(): string {
        return 'performance_schema_query';
    }

    public function getDescription(): string {
        return 'Run SQL query against performance_schema and return results as markdown table';
    }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'required' => ['query'],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'SQL query to run against performance_schema',
                ],
            ],
        ];
    }

    public function handle(string $id, array $input): PromiseInterface {
        $result = $this->queryExecutor->executeQuery('performance_schema', $input['query'], true);

        return Create::promiseFor(new ToolResponse($id, $result));
    }
}
