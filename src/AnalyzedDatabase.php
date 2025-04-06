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
}
