<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Request Payload Size (Bytes)
    |--------------------------------------------------------------------------
    |
    | Limits incoming HTTP request payload size to prevent memory exhaustion
    | and payload bomb attacks. Default: 10MB (10,485,760 bytes).
    |
    */
    'max_request_size_bytes' => (int) env('GATEWAY_MAX_REQUEST_SIZE_BYTES', 10 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Maximum Tokens Ceiling
    |--------------------------------------------------------------------------
    |
    | Maximum ceiling allowed for max_tokens and max_completion_tokens
    | in chat completion requests. Default: 16,384 tokens.
    |
    */
    'max_tokens_ceiling' => (int) env('GATEWAY_MAX_TOKENS_CEILING', 16384),

    /*
    |--------------------------------------------------------------------------
    | Maximum Messages Count
    |--------------------------------------------------------------------------
    |
    | Maximum number of messages allowed in the chat completions messages array.
    | Default: 500 messages.
    |
    */
    'max_messages_count' => (int) env('GATEWAY_MAX_MESSAGES_COUNT', 500),

    /*
    |--------------------------------------------------------------------------
    | Gateway Log Retention (Days)
    |--------------------------------------------------------------------------
    |
    | Number of days to retain gateway request logs before automatic pruning.
    | Set to 0 to disable automated pruning. Default: 30 days.
    |
    */
    'log_retention_days' => (int) env('GATEWAY_LOG_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Log Pruning Batch Chunk Size
    |--------------------------------------------------------------------------
    |
    | Batch size for chunked deletion of old logs to avoid locking database
    | tables during high-traffic gateway operations. Default: 1000.
    |
    */
    'log_prune_chunk_size' => (int) env('GATEWAY_LOG_PRUNE_CHUNK_SIZE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Asynchronous Request Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, gateway request logs are dispatched to the queue
    | asynchronously, eliminating database write latency from client responses.
    | Default: true.
    |
    */
    'async_logging' => (bool) env('GATEWAY_ASYNC_LOGGING', true),
];

