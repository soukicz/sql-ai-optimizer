<?php

namespace Soukicz\SqlAiOptimizer;

use Dibi\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class AnalyzedDatabase {
    private Connection $connection;

    public function __construct(
        #[Autowire(env: 'DATABASE_URL')]
        string $databaseUrl
    ) {
        // Parse the DATABASE_URL
        $parsedUrl = parse_url($databaseUrl);
        $dbConfig = [
            'driver' => 'mysqli',
            'host' => $parsedUrl['host'],
            'username' => $parsedUrl['user'],
            'password' => $parsedUrl['pass'],
            'database' => ltrim($parsedUrl['path'], '/'),
            'port' => $parsedUrl['port'] ?? null,
            'charset' => 'utf8mb4',
        ];

        $this->connection = new Connection($dbConfig);
    }

    public function getConnection(): Connection {
        return $this->connection;
    }

    public function getQueryText(string $digest, string $schema): ?string {
        $sql = $this->connection->query('SELECT sql_text FROM performance_schema.events_statements_history WHERE digest=%s', $digest, ' AND current_schema = %s', $schema)->fetchSingle();
        if (!$sql) {
            $sql = $this->connection->query('SELECT sql_text FROM performance_schema.events_statements_history_long WHERE digest=%s', $digest, ' AND current_schema = %s', $schema)->fetchSingle();
        }

        return $sql;
    }
}
