<?php

namespace Soukicz\SqlAiOptimizer\Result;

use Soukicz\Llm\LLMConversation;

class CandidateResult {
    public function __construct(
        private string $description,
        private array $groups,
        private LLMConversation $conversation,
        private string $formattedConversation
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

    public function getConversation(): LLMConversation {
        return $this->conversation;
    }

    public function getFormattedConversation(): string {
        return $this->formattedConversation;
    }
}
