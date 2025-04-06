<?php

namespace Soukicz\SqlAiOptimizer\Result;

class CandidateResult {
    public function __construct(
        private string $description,
        private array $groups
    ) {
    }

    public function getDescription(): string {
        return $this->description;
    }

    /**
     * @return CandidateQueryGroup[]
     */
    public function getGroups(): array {
        return $this->groups;
    }
}
