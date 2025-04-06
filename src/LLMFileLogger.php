<?php

namespace Soukicz\SqlAiOptimizer;

use Soukicz\Llm\LLMRequest;
use Soukicz\Llm\LLMResponse;
use Soukicz\Llm\Log\LLMLogger;
use Soukicz\Llm\MarkdownFormatter;

readonly class LLMFileLogger implements LLMLogger {

    public function __construct(
        private string            $logPath,
        private MarkdownFormatter $formatter
    ) {
    }

    public function requestStarted(LLMRequest $request): void {
        file_put_contents($this->logPath, $this->formatter->responseToMarkdown($request));
    }

    public function requestFinished(LLMResponse $response): void {
        file_put_contents($this->logPath, $this->formatter->responseToMarkdown($response));
    }
}