<?php

namespace Soukicz\SqlAiOptimizer\Tool;

use Soukicz\Llm\Tool\ToolDefinition;
use Soukicz\Llm\Tool\ToolResponse;
use Soukicz\SqlAiOptimizer\Service\DatabaseQueryExecutor;

class QueryTool implements ToolDefinition {
    public function __construct(
        private DatabaseQueryExecutor $queryExecutor
    ) {
    }

    public function getName(): string {
        return 'database_query';
    }

    public function getDescription(): string {
        return 'Run SQL query against database and return results as markdown table. Use this tool to get better understanding about tables or its data structure. This tool cannot be use to get real data, just metadata about tables, data from system tables or row counts and other statistics.';
    }

    public function getInputSchema(): array {
        return [
            'type' => 'object',
            'required' => ['database', 'query'],
            'properties' => [
                'database' => [
                    'type' => 'string',
                    'description' => 'Database name',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'SQL query to run against database',
                ],
            ],
        ];
    }

    public function handle(array $input): ToolResponse {
        return new ToolResponse($this->queryExecutor->executeQuery($input['database'], $input['query'], false));
    }
}
