<?php

declare(strict_types=1);

namespace App\Agent;

use App\Client\MCPClient;

/**
 * Per-analyze() mutable state shared across tool executions.
 *
 * Passed by value (object handle) instead of by-reference arrays: property
 * writes inside tools are visible to the whole session without by-ref
 * signatures, keeping the tool-call chain type-clean.
 */
final class ToolSession
{
    /**
     * Already-initialized MCP clients keyed by endpoint url; reusing them skips
     * the initialize handshake on repeated tool calls within one request.
     *
     * @var array<int|string, MCPClient>
     */
    public array $mcpClients = [];

    /**
     * Filenames already returned via read_log_file in this session; a second
     * read of the same file is answered with a duplicate notice instead of the
     * content, breaking repeated-read loops.
     *
     * @var array<string, bool>
     */
    public array $readFiles = [];
}
