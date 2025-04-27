<?php

namespace Soukicz\SqlAiOptimizer\Tool;

use Soukicz\Llm\Tool\ToolDefinition;
use Soukicz\Llm\Tool\ToolResponse;
use Soukicz\SqlAiOptimizer\Service\DatabaseQueryExecutor;

class PerformanceSchemaQueryTool implements ToolDefinition {
    public function __construct(
        private DatabaseQueryExecutor $queryExecutor,
        private bool $cacheDatabaseResults
    ) {
    }

    public function getName(): string {
        return 'performance_schema_query';
    }

    public function getDescription(): string {
        return 'Run SQL query against performance_schema and return results as markdown table. Only first 250 rows are returned.';
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

    public function handle(array $input): ToolResponse {
        return new ToolResponse($this->queryExecutor->executeQuery('performance_schema', $input['query'], $this->cacheDatabaseResults, 250));
    }
}
