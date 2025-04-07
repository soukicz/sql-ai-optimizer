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
    public function executeQuery(string $schema, string $query, bool $useCache = true): string {
        if (!$useCache) {
            return $this->doExecuteQuery($schema, $query);
        }

        $cacheKey = 'query_' . md5($schema . '_' . $query);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($schema, $query) {
            $item->expiresAfter($this->cacheTtl);

            return $this->doExecuteQuery($schema, $query);
        });
    }

    /**
     * Execute the query without caching
     *
     * @param string $query The SQL query to execute
     * @return string The query result as a formatted string
     */
    private function doExecuteQuery(string $schema, string $query): string {
        try {
            $connection = $this->database->getConnection();
            $connection->query('USE %n', $schema);
            $rows = $connection->query($query)->fetchAll();

            return $this->formatter->formatAsMarkdownTable($rows);
        } catch (\Exception $e) {
            return "Error: " . $e->getMessage();
        }
    }
}
