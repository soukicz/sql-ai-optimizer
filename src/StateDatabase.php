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
        $statements = explode(';', $sql);

        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                $this->connection->query($statement);
            }
        }
    }

    public function getConnection(): Connection {
        return $this->connection;
    }

    public function createRun(?string $input, string $output, bool $useQuerySample, bool $useDatabaseAccess): int {
        $this->connection->query('INSERT INTO run', [
            'input' => $input,
            'output' => $output,
            'use_query_sample' => $useQuerySample,
            'use_database_access' => $useDatabaseAccess,
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

    public function createQuery(
        int $runId,
        int $groupId,
        string $digest,
        string $schema,
        string $queryText,
        ?string $querySample,
        string $impactDescription
    ): void {
        $this->connection->query('INSERT INTO query', [
            'run_id' => $runId,
            'digest' => $digest,
            'group_id' => $groupId,
            'schema' => $schema,
            'query_text' => $queryText,
            'query_sample' => $querySample,
            'impact_description' => $impactDescription,
        ]);
    }

    public function updateQuerySample(int $queryId, string $querySample): void {
        $this->connection->update('query', [
            'query_sample' => $querySample,
        ])->where('id=%i', $queryId)
        ->execute();
    }

    public function updateQuery(
        int $queryId,
        ?string $queryText = null,
        string $fixInput = null,
        string $fixOutput = null,
        ?string $explainResult = null
    ): void {
        $data = [];

        if ($queryText !== null) {
            $data['query_sample'] = $queryText;
        }
        if ($explainResult !== null) {
            $data['explain_result'] = $explainResult;
        }
        $data['fix_input'] = $fixInput;
        $data['fix_output'] = $fixOutput;

        $this->connection->update('query', $data)->where('id=%i', $queryId)->execute();
    }

    public function getRuns(): array {
        return $this->connection->query('SELECT * FROM run')->fetchAll();
    }

    public function getGroupsByRunId(int $runId): array {
        return $this->connection->query('SELECT * FROM [group] WHERE run_id = %i', $runId)->fetchAll();
    }

    public function getQueriesByRunId(int $runId): array {
        return $this->connection->query('SELECT * FROM query WHERE run_id = %i', $runId)->fetchAll();
    }

    public function getRun(int $id): ?array {
        $result = $this->connection->query('SELECT * FROM run WHERE id = %i', $id)->fetch();
        if ($result) {
            return (array)$result;
        }

        return null;
    }

    public function getQuery(int $id): ?array {
        $result = $this->connection->query('SELECT * FROM query WHERE id = %i', $id)->fetch();
        if ($result) {
            return (array)$result;
        }

        return null;
    }

    public function getGroup(int $id): ?array {
        $result = $this->connection->query('SELECT * FROM [group] WHERE id = %i', $id)->fetch();
        if ($result) {
            return (array)$result;
        }

        return null;
    }

    public function getQueriesWithoutQuerySample(int $runId): array {
        return $this->connection->query('SELECT id, digest, schema FROM query WHERE run_id = %i AND query_sample IS NULL', $runId)->fetchAll();
    }

    public function getQueriesCount(int $runId): int {
        return $this->connection->query('SELECT COUNT(*) FROM query WHERE run_id = %i', $runId)->fetchSingle();
    }
}
