<?php

namespace Soukicz\SqlAiOptimizer\Result;

readonly class CandidateQueryGroup {
    public function __construct(
        private string $name,
        private string $description,
        private array $queries,
    ) {
    }

    public function getName(): string {
        return $this->name;
    }

    public function getDescription(): string {
        return $this->description;
    }

    /**
     * @return CandidateQuery[]
     */
    public function getQueries(): array {
        return $this->queries;
    }
}
