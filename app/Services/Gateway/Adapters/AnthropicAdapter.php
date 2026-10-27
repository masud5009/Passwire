<?php

namespace App\Services\Gateway\Adapters;

class AnthropicAdapter implements ProviderAdapterInterface
{
    public function getFormat(): string
    {
        return 'anthropic';
    }

    public function buildUrl(string $baseUrl, string $model, bool $isStream = false, ?string $apiKey = null): string
    {
        $cleanBase = rtrim($baseUrl, '/');
        if (str_ends_with($cleanBase, '/messages')) {
            return $cleanBase;
        }

        return $cleanBase . '/messages';
    }

    public function formatHeaders(string $apiKey, bool $isStream = false): array
    {
        return [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
            'Accept' => $isStream ? 'text/event-stream' : 'application/json',
        ];
    }

    public function formatCurlHeaders(string $apiKey, bool $isStream = false): array
    {
        return [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
            'Accept: ' . ($isStream ? 'text/event-stream' : 'application/json'),
        ];
    }

    public function formatPayload(string $model, array $openaiPayload): array
    {
        $systemParts = [];
        $anthropicMessages = [];

        foreach ($openaiPayload['messages'] ?? [] as $msg) {
            $role = $msg['role'] ?? 'user';
            $rawContent = $msg['content'] ?? '';

            if ($role === 'system') {
                if (is_string($rawContent)) {
                    $systemParts[] = trim($rawContent);
                } elseif (is_array($rawContent)) {
                    $textParts = [];
                    foreach ($rawContent as $item) {
                        if (is_array($item) && isset($item['text'])) {
                            $textParts[] = $item['text'];
                        } elseif (is_string($item)) {
                            $textParts[] = $item;
                        }
                    }
                    $systemParts[] = implode("\n", $textParts);
                }
                continue;
            }

            // Parse and format message content blocks
            $currContent = null;

            if ($role === 'assistant' && !empty($msg['tool_calls'])) {
                // Assistant message triggering tool calls
                $blocks = [];
                if (is_string($rawContent) && trim($rawContent) !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $rawContent];
                }

                foreach ($msg['tool_calls'] as $tc) {
                    $args = $tc['function']['arguments'] ?? [];
                    if (is_string($args)) {
                        $decoded = json_decode($args, true);
                        $args = is_array($decoded) ? $decoded : ($args === '' ? new \stdClass() : ['raw' => $args]);
                    }
                    if (empty($args)) {
                        $args = new \stdClass();
                    }

                    $blocks[] = [
                        'type' => 'tool_use',
                        'id' => $tc['id'] ?? ('call_' . bin2hex(random_bytes(6))),
                        'name' => $tc['function']['name'] ?? '',
                        'input' => $args,
                    ];
                }
                $currContent = $blocks;
            } elseif ($role === 'tool') {
                // User returning tool execution result
                $contentStr = is_string($rawContent) ? $rawContent : json_encode($rawContent, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $currContent = [
                    [
                        'type' => 'tool_result',
                        'tool_use_id' => $msg['tool_call_id'] ?? '',
                        'content' => $contentStr,
                    ],
                ];
            } elseif (is_array($rawContent)) {
                // Multimodal or structured content
                $blocks = [];
                foreach ($rawContent as $item) {
                    if (is_array($item) && ($item['type'] ?? '') === 'image_url' && !empty($item['image_url']['url'])) {
                        $url = $item['image_url']['url'];
                        if (str_starts_with($url, 'data:')) {
                            if (preg_match('/^data:([^;]+);base64,(.+)$/', $url, $m)) {
                                $blocks[] = [
                                    'type' => 'image',
                                    'source' => [
                                        'type' => 'base64',
                                        'media_type' => $m[1],
                                        'data' => $m[2],
                                    ],
                                ];
                            }
                        }
                    } elseif (is_array($item) && ($item['type'] ?? '') === 'text') {
                        $blocks[] = ['type' => 'text', 'text' => $item['text'] ?? ''];
                    } elseif (is_string($item)) {
                        $blocks[] = ['type' => 'text', 'text' => $item];
                    }
                }
                $currContent = !empty($blocks) ? $blocks : '';
            } else {
                $currContent = (string) $rawContent;
            }

            // Map role for Anthropic ('user' or 'assistant'). Tool results map to 'user'
            $mappedRole = ($role === 'assistant') ? 'assistant' : 'user';

            // Anthropic strictly requires alternating roles. Merge consecutive turns with the same role.
            $lastIndex = count($anthropicMessages) - 1;
            if ($lastIndex >= 0 && $anthropicMessages[$lastIndex]['role'] === $mappedRole) {
                $prev = $anthropicMessages[$lastIndex]['content'];

                if (is_array($prev) || is_array($currContent)) {
                    $prevBlocks = is_array($prev) ? $prev : [['type' => 'text', 'text' => (string) $prev]];
                    $currBlocks = is_array($currContent) ? $currContent : [['type' => 'text', 'text' => (string) $currContent]];
                    $anthropicMessages[$lastIndex]['content'] = array_merge($prevBlocks, $currBlocks);
                } else {
                    $anthropicMessages[$lastIndex]['content'] = (string) $prev . "\n\n" . (string) $currContent;
                }
            } else {
                $anthropicMessages[] = [
                    'role' => $mappedRole,
                    'content' => $currContent,
                ];
            }
        }

        // Anthropic requires the first message to be from 'user'
        if (!empty($anthropicMessages) && $anthropicMessages[0]['role'] !== 'user') {
            array_unshift($anthropicMessages, [
                'role' => 'user',
                'content' => 'Hello',
            ]);
        }

        // Clean model name if provider prefix exists (e.g. "anthropic/claude-3-5-sonnet-20241022" -> "claude-3-5-sonnet-20241022")
        $targetModel = str_contains($model, '/') ? explode('/', $model, 2)[1] : $model;

        $payload = [
            'model' => $targetModel,
            'messages' => $anthropicMessages,
            'max_tokens' => (int) ($openaiPayload['max_tokens'] ?? $openaiPayload['max_completion_tokens'] ?? 4096),
        ];

        if (!empty($systemParts)) {
            $payload['system'] = implode("\n\n", array_filter($systemParts));
        }

        if (isset($openaiPayload['temperature'])) {
            $payload['temperature'] = (float) $openaiPayload['temperature'];
        }

        if (isset($openaiPayload['top_p'])) {
            $payload['top_p'] = (float) $openaiPayload['top_p'];
        }

        if (!empty($openaiPayload['stream'])) {
            $payload['stream'] = true;
        }

        if (isset($openaiPayload['stop'])) {
            $payload['stop_sequences'] = is_array($openaiPayload['stop'])
                ? $openaiPayload['stop']
                : [$openaiPayload['stop']];
        }

        // Translate OpenAI tools to Anthropic format
        if (!empty($openaiPayload['tools']) && is_array($openaiPayload['tools'])) {
            $anthropicTools = [];
            foreach ($openaiPayload['tools'] as $tool) {
                if (($tool['type'] ?? '') === 'function' && isset($tool['function'])) {
                    $fn = $tool['function'];
                    $schema = $fn['parameters'] ?? [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ];
                    $anthropicTools[] = [
                        'name' => $fn['name'],
                        'description' => $fn['description'] ?? '',
                        'input_schema' => $schema,
                    ];
                }
            }

            if (!empty($anthropicTools)) {
                $payload['tools'] = $anthropicTools;

                if (isset($openaiPayload['tool_choice'])) {
                    $tc = $openaiPayload['tool_choice'];
                    if ($tc === 'auto') {
                        $payload['tool_choice'] = ['type' => 'auto'];
                    } elseif ($tc === 'required') {
                        $payload['tool_choice'] = ['type' => 'any'];
                    } elseif (is_array($tc) && isset($tc['function']['name'])) {
                        $payload['tool_choice'] = [
                            'type' => 'tool',
                            'name' => $tc['function']['name'],
                        ];
                    }
                }
            }
        }

        return $payload;
    }

    public function parseResponse(array $rawResponse, string $model): array
    {
        $content = '';
        $toolCalls = [];

        if (isset($rawResponse['content'])) {
            if (is_array($rawResponse['content'])) {
                foreach ($rawResponse['content'] as $block) {
                    if (is_array($block)) {
                        $bType = $block['type'] ?? '';
                        if ($bType === 'text') {
                            $content .= $block['text'] ?? '';
                        } elseif ($bType === 'tool_use') {
                            $toolCalls[] = [
                                'id' => $block['id'] ?? ('call_' . bin2hex(random_bytes(8))),
                                'type' => 'function',
                                'function' => [
                                    'name' => $block['name'] ?? '',
                                    'arguments' => json_encode($block['input'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                                ],
                            ];
                        }
                    } elseif (is_string($block)) {
                        $content .= $block;
                    }
                }
            } elseif (is_string($rawResponse['content'])) {
                $content = $rawResponse['content'];
            }
        }

        $stopReason = $rawResponse['stop_reason'] ?? 'end_turn';
        $finishReason = match ($stopReason) {
            'tool_use' => 'tool_calls',
            'end_turn' => 'stop',
            'max_tokens' => 'length',
            'stop_sequence' => 'stop',
            default => !empty($toolCalls) ? 'tool_calls' : 'stop',
        };

        $promptTokens = (int) ($rawResponse['usage']['input_tokens'] ?? 0);
        $completionTokens = (int) ($rawResponse['usage']['output_tokens'] ?? 0);

        $message = [
            'role' => 'assistant',
            'content' => $content !== '' ? $content : null,
        ];

        if (!empty($toolCalls)) {
            $message['tool_calls'] = $toolCalls;
        }

        return [
            'id' => $rawResponse['id'] ?? ('chatcmpl-' . bin2hex(random_bytes(12))),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'message' => $message,
                    'finish_reason' => $finishReason,
                ],
            ],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
            ],
        ];
    }

    public function transformStreamChunk(string $rawChunk, string $model): ?string
    {
        $line = trim($rawChunk);
        if ($line === '' || $line === 'event: ping') {
            return null;
        }

        if (str_starts_with($line, 'event: message_stop') || $line === 'data: [DONE]') {
            return "data: [DONE]\n\n";
        }

        $jsonStr = $line;
        if (str_starts_with($line, 'data: ')) {
            $jsonStr = trim(substr($line, 6));
        }

        if ($jsonStr === '[DONE]') {
            return "data: [DONE]\n\n";
        }

        $data = json_decode($jsonStr, true);
        if (!is_array($data)) {
            return null;
        }

        $type = $data['type'] ?? '';

        if ($type === 'message_stop') {
            return "data: [DONE]\n\n";
        }

        // Tool use starting
        if ($type === 'content_block_start' && isset($data['content_block']['type']) && $data['content_block']['type'] === 'tool_use') {
            $cb = $data['content_block'];
            $chunk = [
                'id' => 'chatcmpl-chunk-' . substr(md5($model . microtime()), 0, 12),
                'object' => 'chat.completion.chunk',
                'created' => time(),
                'model' => $model,
                'choices' => [
                    [
                        'index' => 0,
                        'delta' => [
                            'tool_calls' => [
                                [
                                    'index' => $data['index'] ?? 0,
                                    'id' => $cb['id'] ?? ('call_' . bin2hex(random_bytes(6))),
                                    'type' => 'function',
                                    'function' => [
                                        'name' => $cb['name'] ?? '',
                                        'arguments' => '',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            return 'data: ' . json_encode($chunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        }

        // Text delta or input_json_delta for tool calls
        if ($type === 'content_block_delta') {
            $d = $data['delta'] ?? [];
            $dType = $d['type'] ?? '';

            if ($dType === 'input_json_delta') {
                $chunk = [
                    'id' => 'chatcmpl-chunk-' . substr(md5($model . microtime()), 0, 12),
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => $model,
                    'choices' => [
                        [
                            'index' => 0,
                            'delta' => [
                                'tool_calls' => [
                                    [
                                        'index' => $data['index'] ?? 0,
                                        'function' => [
                                            'arguments' => $d['partial_json'] ?? '',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ];

                return 'data: ' . json_encode($chunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
            }

            if ($dType === 'text_delta' || isset($d['text'])) {
                $text = $d['text'] ?? '';
                $chunk = [
                    'id' => 'chatcmpl-chunk-' . substr(md5($model . microtime()), 0, 12),
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => $model,
                    'choices' => [
                        [
                            'index' => 0,
                            'delta' => [
                                'content' => $text,
                            ],
                        ],
                    ],
                ];

                return 'data: ' . json_encode($chunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
            }
        }

        // Message finish reason delta
        if ($type === 'message_delta') {
            $stopReason = $data['delta']['stop_reason'] ?? null;
            if ($stopReason !== null) {
                $finishReason = match ($stopReason) {
                    'tool_use' => 'tool_calls',
                    'end_turn' => 'stop',
                    'max_tokens' => 'length',
                    default => 'stop',
                };

                $chunk = [
                    'id' => 'chatcmpl-chunk-' . substr(md5($model . microtime()), 0, 12),
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => $model,
                    'choices' => [
                        [
                            'index' => 0,
                            'delta' => new \stdClass(),
                            'finish_reason' => $finishReason,
                        ],
                    ],
                ];

                return 'data: ' . json_encode($chunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
            }
        }

        return null;
    }
}
