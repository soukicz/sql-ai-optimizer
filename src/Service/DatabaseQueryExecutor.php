<?php

namespace Soukicz\SqlAiOptimizer\Service;

use Soukicz\SqlAiOptimizer\AnalyzedDatabase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

class DatabaseQueryExecutor {
    public function __construct(
        private AnalyzedDatabase $database,
        private FilesystemAdapter $cache,
        private QueryResultFormatter $formatter,
        private int $cacheTtl = 24 * 60 * 60
    ) {
    }

    /**
     * Execute an SQL query with caching
     *
     * @param string $query The SQL query to execute
     * @param bool $useCache Whether to use cache or not
     * @return string The query result as a formatted string
     */
    public function executeQuery(string $schema, string $query, bool $useCache = true, ?int $maxRows = null): string {
        if (!$useCache) {
            return $this->doExecuteQuery($schema, $query);
        }

        $cacheKey = 'query_' . md5($schema . '_' . $query);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($schema, $query, $maxRows) {
            $item->expiresAfter($this->cacheTtl);

            return $this->doExecuteQuery($schema, $query, $maxRows);
        });
    }

    /**
     * Execute the query without caching
     *
     * @param string $query The SQL query to execute
     * @return string The query result as a formatted string
     */
    private function doExecuteQuery(string $schema, string $query, ?int $maxRows = null): string {
        try {
            $connection = $this->database->getConnection();
            $connection->query('USE %n', $schema);
            $rows = $connection->query($query)->fetchAll();

            $totalRows = count($rows);
            if (isset($maxRows) && $totalRows > $maxRows) {
                return "Note: Query returned $totalRows rows. Only first $maxRows rows are displayed.\n\n" .
                $this->formatter->formatAsMarkdownTable(array_slice($rows, 0, $maxRows));
            }

            return $this->formatter->formatAsMarkdownTable($rows);
        } catch (\Exception $e) {
            return "Error: " . $e->getMessage();
        }
    }
}
