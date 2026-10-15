<?php

use App\Mcp\Servers\MemoryServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/memory', MemoryServer::class)->middleware(['auth:sanctum', 'abilities:*', 'throttle:mcp']);
