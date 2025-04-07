<?php

namespace Soukicz\SqlAiOptimizer\Service;

class QueryResultFormatter {
    /**
     * Format query results as a markdown table
     *
     * @param array $rows The query result rows
     * @return string The formatted markdown table or a message if no results
     */
    public function formatAsMarkdownTable(array $rows): string {
        if (count($rows) === 0) {
            return "No results found.";
        }

        // Create markdown table
        $headers = array_keys((array)$rows[0]);
        $result = "| " . implode(" | ", $headers) . " |\n";
        $result .= "| " . implode(" | ", array_fill(0, count($headers), "---")) . " |\n";

        foreach ($rows as $row) {
            $rowArray = (array)$row;
            $result .= "| " . implode(" | ", array_map(function ($value) {
                return $value === null ? 'NULL' : (string)$value;
            }, $rowArray)) . " |\n";
        }

        return $result;
    }
}
