<?php

namespace Soukicz\SqlAiOptimizer;

use Dibi\DriverException;
use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\Llm\Client\Anthropic\AnthropicClient;
use Soukicz\Llm\Client\LLMChainClient;
use Soukicz\Llm\Config\ReasoningBudget;
use Soukicz\Llm\LLMConversation;
use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Message\LLMMessage;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\SqlAiOptimizer\Result\CandidateQuery;

readonly class QueryAnalyzer {
    public function __construct(
        private LLMChainClient $llmChainClient,
        private AnthropicClient $anthropicClient,
        private AnalyzedDatabase $analyzedDatabase,
        private StateDatabase $stateDatabase
    ) {
    }

    public function analyzeQuery(int $queryId, ?string $rawSql, CandidateQuery $candidateQuery): PromiseInterface {
        if (!$rawSql) {
            $rawSql = $this->analyzedDatabase->getQueryText($candidateQuery->getDigest(), $candidateQuery->getSchema());
            if ($rawSql) {
                $this->stateDatabase->updateQuerySample(
                    queryId: $queryId,
                    querySample: $rawSql
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

        $promptSql = $rawSql ?? $candidateQuery->getQueryText();

        $prompt = <<<EOT
        I need help with optimizing MySQL 8 query. I have large database and query is consuming too much resources. I will provide you with example query and schema of tables used in query.
        
        Analyze all information and provide me with instructions to change the query, update schema or how to split to more manageable queries in PHP.
        
        ### Query description from performance schema
        
        This description was created based on data from all query runs as reported by performance schema.

        ```
        {$candidateQuery->getImpactDescription()}
        ```

        ### Query
        ```
        $promptSql
        ```

        EOT;

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
            reasoningConfig: new ReasoningBudget(20_000)
        );

        return $this->llmChainClient->runAsync(
            client: $this->anthropicClient,
            request: $request,
        )->then(function (LLMResponse $response) use ($queryId, $rawSql, $explainJson, $prompt) {
            $this->stateDatabase->updateQuery(
                queryId: $queryId,
                queryText: $rawSql,
                fixInput: $prompt,
                fixOutput: $response->getLastText(),
                explainResult: $explainJson
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
