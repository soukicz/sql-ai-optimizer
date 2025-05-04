<?php

namespace Soukicz\SqlAiOptimizer;

use Dibi\Connection;
use Soukicz\Llm\LLMConversation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class StateDatabase {
    private Connection $connection;

    private string $databasePath;

    public function __construct(
        string $databasePath
    ) {
        $this->databasePath = $databasePath;
        $this->connect();
    }

    private function connect(): void {
        $needsInitialization = !file_exists($this->databasePath);

        if (!is_dir(dirname($this->databasePath))) {
            mkdir(dirname($this->databasePath), 0777, true);
        }

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

    public function createRun(
        ?string $input,
        string $hostname,
        string $output,
        bool $useRealQuery,
        bool $useDatabaseAccess,
        LLMConversation $conversation,
        string $conversationMarkdown
    ): int {
        $this->connection->query('INSERT INTO run', [
            'input' => $input,
            'hostname' => $hostname,
            'output' => $output,
            'use_real_query' => $useRealQuery,
            'use_database_access' => $useDatabaseAccess,
            'llm_conversation' => json_encode($conversation->jsonSerialize(), JSON_THROW_ON_ERROR),
            'llm_conversation_markdown' => $conversationMarkdown,
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
        string $normalizedQuery,
        ?string $realQuery,
        string $impactDescription
    ): void {
        $this->connection->query('INSERT INTO query', [
            'run_id' => $runId,
            'digest' => $digest,
            'group_id' => $groupId,
            'schema' => $schema,
            'normalized_query' => $normalizedQuery,
            'real_query' => $realQuery,
            'impact_description' => $impactDescription,
        ]);
    }

    public function setRealQuery(int $queryId, string $sql): void {
        $this->connection->update('query', [
            'real_query' => $sql,
        ])->where('id=%i', $queryId)
        ->execute();
    }

    public function updateConversation(
        int $queryId,
        LLMConversation $conversation,
        string $conversationMarkdown
    ): void {
        $data = [];

        $data['llm_conversation'] = json_encode($conversation->jsonSerialize(), JSON_THROW_ON_ERROR);
        $data['llm_conversation_markdown'] = $conversationMarkdown;
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

    public function getQueriesWithoutRealQuery(int $runId): array {
        return $this->connection->query('SELECT id, digest, schema FROM query WHERE run_id = %i AND real_query IS NULL', $runId)->fetchAll();
    }

    public function getQueriesCount(int $runId): int {
        return $this->connection->query('SELECT COUNT(*) FROM query WHERE run_id = %i', $runId)->fetchSingle();
    }

    public function deleteRun(int $runId): void {
        $this->connection->query('DELETE FROM query WHERE run_id = %i', $runId);
        $this->connection->query('DELETE FROM `group` WHERE run_id = %i', $runId);
        $this->connection->query('DELETE FROM run WHERE id = %i', $runId);
    }
}
