<?php

namespace Soukicz\SqlAiOptimizer;

use Dibi\DriverException;
use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\Client\LLMClient;
use Soukicz\Llm\Config\ReasoningBudget;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\SqlAiOptimizer\Result\CandidateQuery;
use Soukicz\SqlAiOptimizer\Service\DatabaseQueryExecutor;
use Soukicz\SqlAiOptimizer\Tool\QueryTool;

readonly class QueryAnalyzer {
    public function __construct(
        private LLMChainClient $llmChainClient,
        private LLMClient $llmClient,
        private AnalyzedDatabase $analyzedDatabase,
        private StateDatabase $stateDatabase,
        private QueryTool $queryTool,
        private DatabaseQueryExecutor $databaseQueryExecutor
    ) {
    }

    public function analyzeQuery(int $queryId, ?string $rawSql, CandidateQuery $candidateQuery, bool $useRealQuery, bool $useDatabaseAccess): PromiseInterface {
        if (!$rawSql) {
            $rawSql = $this->analyzedDatabase->getQueryText($candidateQuery->getDigest(), $candidateQuery->getSchema());
            if ($rawSql) {
                $this->stateDatabase->setRealQuery(
                    queryId: $queryId,
                    sql: $rawSql
                );
            }
        }

        $explainJson = null;
        if ($rawSql) {
            $this->analyzedDatabase->getConnection()->query('USE %n', $candidateQuery->getSchema());

            try {
                $explainJson = $this->analyzedDatabase->getConnection()
                ->query('EXPLAIN format=json %sql', $rawSql)
                ->fetchSingle();
            } catch (DriverException) {
                $explainJson = null;
            }
        }

        if ($rawSql && $useRealQuery) {
            $promptSql = $rawSql;
        } else {
            $promptSql = $candidateQuery->getNormalizedQuery();
        }

        $prompt = <<<EOT
        I need help with optimizing a MySQL 8 query. I have identified this query using performance schema as consuming too many resources. I will provide you with an example query and the schema of tables used in the query.
        
        Analyze all information and provide me with instructions to change the query, update schema or how to split to more manageable queries in PHP.
        
        Database: {$candidateQuery->getSchema()}

        Query digest: {$candidateQuery->getDigest()}

        ### Query
        ```
        $promptSql
        ```

        EOT;

        if ($useDatabaseAccess) {
            $prompt .= <<<EOT

        ### Additional information
        Use provided tool to get more information about tables or its data structure if needed.

        EOT;
        }

        /**
                ### Query description from performance schema

                This description was created based on data from all query runs as reported by performance schema.

                ```
                {$candidateQuery->getImpactDescription()}
                ```
        */

        if (isset($explainJson)) {
            $prompt .= <<<EOT
        
        ### Explain result
        ```
        $explainJson
        ```

        EOT;
        }

        $prompt .= <<<EOT
        ### Schema
        EOT;

        $this->analyzedDatabase->getConnection()->query('USE %n', $candidateQuery->getSchema());
        foreach ($this->getTablesFromSelectQuery($promptSql) as $table) {
            $schema = $this->analyzedDatabase->getConnection()
                ->query('SHOW CREATE TABLE %n', $table)->fetch()['Create Table'];

            $prompt .= "\n\n#### $table\n```\n$schema\n```\n";

            // Add table indexes information
            $indexes = $this->analyzedDatabase->getConnection()
                ->query('SHOW INDEX FROM %n', $table)->fetchAll();

            if (!empty($indexes)) {
                $prompt .= "\n#### Indexes for $table\n```\n";

                // Group indexes by name to handle multi-column indexes
                $groupedIndexes = [];
                foreach ($indexes as $index) {
                    $indexName = $index['Key_name'];
                    if (!isset($groupedIndexes[$indexName])) {
                        $groupedIndexes[$indexName] = [
                            'name' => $indexName,
                            'columns' => [],
                            'unique' => !$index['Non_unique'],
                            'index_type' => $index['Index_type'],
                        ];
                    }
                    // Sort columns by their position in the index
                    $groupedIndexes[$indexName]['columns'][$index['Seq_in_index']] = $index['Column_name'];
                }

                // Generate SQL for each index
                foreach ($groupedIndexes as $index) {
                    // Skip PRIMARY key as it's already in CREATE TABLE statement
                    if ($index['name'] === 'PRIMARY') {
                        continue;
                    }

                    // Sort columns by position
                    ksort($index['columns']);
                    $columnList = implode(', ', $index['columns']);

                    // Build the CREATE INDEX statement
                    $uniqueKeyword = $index['unique'] ? 'UNIQUE ' : '';
                    $prompt .= "ALTER TABLE `$table` ADD {$uniqueKeyword}INDEX `{$index['name']}` ($columnList) USING {$index['index_type']};\n";
                }
                $prompt .= "```\n";

                $indexStats = $this->databaseQueryExecutor->executeQuery($candidateQuery->getSchema(), "SHOW INDEX FROM $table");
                $prompt .= "\n\n#### SHOW INDEX FROM $table\n\n$indexStats\n\n";
            }
        }

        $tools = [];
        if ($useDatabaseAccess) {
            $tools[] = $this->queryTool;
        }

        $request = new LLMRequest(
            model: AnthropicClient::MODEL_SONNET_37_20250219,
            conversation: new LLMConversation([
                LLMMessage::createFromUser([
                    new LLMMessageText($prompt),
                ]),
            ]),
            temperature: 1.0,
            maxTokens: 30_000,
            reasoningConfig: new ReasoningBudget(20_000),
            tools: $tools
        );

        return $this->llmChainClient->runAsync(
            client: $this->llmClient,
            request: $request,
        )->then(function (LLMResponse $response) use ($queryId) {
            $this->stateDatabase->updateQuery(
                queryId: $queryId,
                fixOutput: $response->getLastText()
            );
        });
    }

    /**
     * Extract all table names from a SQL SELECT query.
     *
     * @param string $sql The SQL SELECT query.
     * @return array      An array of unique table names found in the query.
     */
    private function getTablesFromSelectQuery($sql) {
        // Normalize whitespace
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        // This regex looks for:
        // (?:FROM|JOIN)  - non-capturing group to match FROM or JOIN
        // \s+            - one or more whitespace characters
        // ([a-zA-Z0-9_.`]+) - captures table name which can include letters, numbers, underscore, dot, or backticks
        //
        // Note: If you expect quoted identifiers with double quotes, you may need to adjust the pattern.
        $pattern = '/(?:FROM|JOIN)\s+([a-zA-Z0-9_.`]+)/i';

        // Find all matches
        preg_match_all($pattern, $sql, $matches);

        // $matches[1] should hold the captured table names
        $tables = $matches[1];

        // Clean up backticks, if any
        $tables = array_map(function ($table) {
            // Remove backticks around the table name (e.g. `schema`.`table` => schema.table)
            return str_replace('`', '', $table);
        }, $tables);

        // Ensure they are unique
        $tables = array_unique($tables);

        // Reindex and return
        return array_values($tables);
    }
}
