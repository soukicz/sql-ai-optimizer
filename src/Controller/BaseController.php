<?php

namespace Soukicz\SqlAiOptimizer\Controller;

use League\CommonMark\Environment\Environment as CommonMarkEnvironment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;

class BaseController {
    /**
     * Renders markdown content to HTML with syntax highlighting for code blocks
     */
    protected function renderMarkdownWithHighlighting(string $markdown): string {
        $environment = new CommonMarkEnvironment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        // Add the core CommonMark rules
        $environment->addExtension(new CommonMarkCoreExtension());

        // Add the GFM extensions
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new TaskListExtension());

        // Create the converter
        $converter = new MarkdownConverter($environment);

        // Convert markdown to HTML
        return $converter->convert($markdown)->getContent();
    }
}
