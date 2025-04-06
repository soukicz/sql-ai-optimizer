<?php

namespace Soukicz\SqlAiOptimizer;

use Dibi\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class StateDatabase {
    private Connection $connection;

    private string $databasePath;

    public function __construct(
        #[Autowire(env: 'SQLITE_DATABASE_PATH', default: 'state.sqlite')]
        string $databasePath
    ) {
        $this->databasePath = $databasePath;
        $this->connect();
    }

    private function connect(): void {
        $needsInitialization = !file_exists($this->databasePath);

        $this->connection = new Connection([
            'driver' => 'sqlite3',
            'database' => $this->databasePath,
        ]);

        if ($needsInitialization) {
            $this->initializeDatabase();
        }
    }

    private function initializeDatabase(): void {
        $sql = file_get_contents(__DIR__ . '/schema/state_database.sql');
        $this->connection->query($sql);
    }

    public function getConnection(): Connection {
        return $this->connection;
    }

    public function createRun(string $description): int {
        $this->connection->query('INSERT INTO run', [
            'description' => $description,
        ]);

        return $this->connection->getInsertId();
    }

    public function createGroup(int $runId, string $name, string $description): int {
        $this->connection->query('INSERT INTO `group`', [
            'run_id' => $runId,
            'name' => $name,
            'description' => $description,
        ]);

        return $this->connection->getInsertId();
    }

    public function saveQuery(
        string $digest,
        int $groupId,
        string $schema,
        string $querySample,
        string $impactDescription
    ): void {
        $this->connection->query('INSERT INTO query', [
            'digest' => $digest,
            'group_id' => $groupId,
            'schema' => $schema,
            'query_sample' => $querySample,
            'impact_description' => $impactDescription,
        ]);
    }

    public function updateQuery(
        int $groupId,
        string $digest,
        ?string $queryText = null,
        ?string $fixInput = null,
        ?string $fixOutput = null,
        ?string $explainResult = null
    ): void {
        $data = [];

        if ($queryText !== null) {
            $data['query_text'] = $queryText;
        }

        if ($fixInput !== null) {
            $data['fix_input'] = $fixInput;
        }

        if ($fixOutput !== null) {
            $data['fix_output'] = $fixOutput;
        }

        if ($explainResult !== null) {
            $data['explain_result'] = $explainResult;
        }

        if (empty($data)) {
            return;
        }

        $this->connection->query('UPDATE query SET', $data, 'WHERE digest = %s', $digest,' AND group_di = %i', $groupId);
    }
}
