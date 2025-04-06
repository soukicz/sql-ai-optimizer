<?php

namespace Soukicz\SqlAiOptimizer\Tool;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\Llm\Tool\ToolDefinition;
use Soukicz\Llm\Tool\ToolResponse;
use Soukicz\SqlAiOptimizer\AnalyzedDatabase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

class PerfomanceSchemaQueryTool extends ToolDefinition {
    public function __construct(
        private AnalyzedDatabase $database,
        private FilesystemAdapter $cache
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
        $cacheKey = 'query_' . md5($input['query']);

        $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($input) {
            try {
                // Use the AnalyzedDatabase connection
                $connection = $this->database->getConnection();

                $rows = $connection->query($input['query'])->fetchAll();

                if (count($rows) > 0) {
                    // Create markdown table
                    $headers = array_keys((array)$rows[0]);
                    $result = "| " . implode(" | ", $headers) . " |\n";
                    $result .= "| " . implode(" | ", array_fill(0, count($headers), "---")) . " |\n";

                    foreach ($rows as $row) {
                        $rowArray = (array)$row;
                        $result .= "| " . implode(" | ", array_map(function ($value) {
                            return $value === null ? 'NULL' : (string)$value;
                        }, $rowArray)) . " |\n";
                    }
                } else {
                    $result = "No results found.";
                }
                $item->expiresAfter(30 * 60);
            } catch (\Exception $e) {
                $result = "Error: " . $e->getMessage();
            }

            return $result;
        });

        return Create::promiseFor(new ToolResponse($id, $result));
    }
}
