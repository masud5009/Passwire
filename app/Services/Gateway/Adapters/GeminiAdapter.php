<?php

namespace App\Services\Gateway\Adapters;

use stdClass;

class GeminiAdapter implements ProviderAdapterInterface
{
    public function getFormat(): string
    {
        return 'gemini';
    }

    public function buildUrl(string $baseUrl, string $model, bool $isStream = false, ?string $apiKey = null): string
    {
        $cleanBase = rtrim($baseUrl, '/');

        // Extract model name without provider prefix (e.g. "gemini/gemini-1.5-flash" -> "gemini-1.5-flash")
        $targetModel = str_contains($model, '/') ? explode('/', $model, 2)[1] : $model;

        // Strip "models/" prefix if user included it
        if (str_starts_with($targetModel, 'models/')) {
            $targetModel = substr($targetModel, 7);
        }

        $action = $isStream ? 'streamGenerateContent?alt=sse' : 'generateContent';
        $url = "{$cleanBase}/models/{$targetModel}:{$action}";

        if ($apiKey) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= "{$separator}key={$apiKey}";
        }

        return $url;
    }

    public function formatHeaders(string $apiKey, bool $isStream = false): array
    {
        return [
            'x-goog-api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => $isStream ? 'text/event-stream' : 'application/json',
        ];
    }

    public function formatCurlHeaders(string $apiKey, bool $isStream = false): array
    {
        return [
            'x-goog-api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: ' . ($isStream ? 'text/event-stream' : 'application/json'),
        ];
    }

    public function formatPayload(string $model, array $openaiPayload): array
    {
        $systemParts = [];
        $contents = [];

        // Build mapping of tool_call_id => function_name for tool role responses
        $callIdToName = [];
        foreach ($openaiPayload['messages'] ?? [] as $m) {
            if (!empty($m['tool_calls']) && is_array($m['tool_calls'])) {
                foreach ($m['tool_calls'] as $tc) {
                    if (!empty($tc['id']) && !empty($tc['function']['name'])) {
                        $callIdToName[$tc['id']] = $tc['function']['name'];
                    }
                }
            }
        }

        foreach ($openaiPayload['messages'] ?? [] as $msg) {
            $role = $msg['role'] ?? 'user';
            $rawContent = $msg['content'] ?? '';

            if ($role === 'system') {
                $text = '';
                if (is_string($rawContent)) {
                    $text = $rawContent;
                } elseif (is_array($rawContent)) {
                    $textParts = [];
                    foreach ($rawContent as $item) {
                        if (is_array($item) && isset($item['text'])) {
                            $textParts[] = $item['text'];
                        } elseif (is_string($item)) {
                            $textParts[] = $item;
                        }
                    }
                    $text = implode("\n", $textParts);
                }
                if (trim($text) !== '') {
                    $systemParts[] = ['text' => trim($text)];
                }
                continue;
            }

            $parts = [];
            $geminiRole = 'user';

            if ($role === 'assistant' && !empty($msg['tool_calls'])) {
                $geminiRole = 'model';
                if (is_string($rawContent) && trim($rawContent) !== '') {
                    $parts[] = ['text' => $rawContent];
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

                    $parts[] = [
                        'functionCall' => [
                            'name' => $tc['function']['name'] ?? '',
                            'args' => $args,
                        ],
                    ];
                }
            } elseif ($role === 'tool') {
                $geminiRole = 'user';
                $fnName = $msg['name'] ?? ($callIdToName[$msg['tool_call_id'] ?? ''] ?? 'tool_result');
                $responseContent = is_string($rawContent)
                    ? (json_decode($rawContent, true) ?? ['result' => $rawContent])
                    : (is_array($rawContent) ? $rawContent : ['result' => $rawContent]);

                $parts[] = [
                    'functionResponse' => [
                        'name' => $fnName,
                        'response' => [
                            'name' => $fnName,
                            'content' => $responseContent,
                        ],
                    ],
                ];
            } else {
                $geminiRole = ($role === 'assistant') ? 'model' : 'user';

                if (is_array($rawContent)) {
                    foreach ($rawContent as $item) {
                        if (is_array($item) && ($item['type'] ?? '') === 'image_url' && !empty($item['image_url']['url'])) {
                            $url = $item['image_url']['url'];
                            if (str_starts_with($url, 'data:')) {
                                if (preg_match('/^data:([^;]+);base64,(.+)$/', $url, $m)) {
                                    $parts[] = [
                                        'inline_data' => [
                                            'mime_type' => $m[1],
                                            'data' => $m[2],
                                        ],
                                    ];
                                }
                            }
                        } elseif (is_array($item) && ($item['type'] ?? '') === 'text') {
                            $parts[] = ['text' => $item['text'] ?? ''];
                        } elseif (is_string($item)) {
                            $parts[] = ['text' => $item];
                        }
                    }
                } else {
                    $parts[] = ['text' => (string) $rawContent];
                }
            }

            // Gemini forbids consecutive turns with the same role. Combine parts if necessary.
            $lastIndex = count($contents) - 1;
            if ($lastIndex >= 0 && $contents[$lastIndex]['role'] === $geminiRole) {
                $contents[$lastIndex]['parts'] = array_merge($contents[$lastIndex]['parts'], $parts);
            } else {
                $contents[] = [
                    'role' => $geminiRole,
                    'parts' => !empty($parts) ? $parts : [['text' => '']],
                ];
            }
        }

        // Gemini conversation must start with 'user'
        if (!empty($contents) && $contents[0]['role'] !== 'user') {
            array_unshift($contents, [
                'role' => 'user',
                'parts' => [['text' => 'Hello']],
            ]);
        }

        if (empty($contents)) {
            $contents = [
                [
                    'role' => 'user',
                    'parts' => [['text' => 'Hello']],
                ]
            ];
        }

        $generationConfig = [];
        if (isset($openaiPayload['temperature'])) {
            $generationConfig['temperature'] = (float) $openaiPayload['temperature'];
        }

        if (isset($openaiPayload['top_p'])) {
            $generationConfig['topP'] = (float) $openaiPayload['top_p'];
        }

        $maxTokens = $openaiPayload['max_tokens'] ?? $openaiPayload['max_completion_tokens'] ?? null;
        if ($maxTokens !== null) {
            $generationConfig['maxOutputTokens'] = (int) $maxTokens;
        }

        if (!empty($openaiPayload['stop'])) {
            $generationConfig['stopSequences'] = is_array($openaiPayload['stop'])
                ? $openaiPayload['stop']
                : [$openaiPayload['stop']];
        }

        $geminiPayload = [
            'contents' => $contents,
        ];

        if (!empty($systemParts)) {
            $geminiPayload['system_instruction'] = [
                'parts' => $systemParts,
            ];
        }

        if (!empty($generationConfig)) {
            $geminiPayload['generationConfig'] = $generationConfig;
        }

        // Translate OpenAI tools to Gemini format
        if (!empty($openaiPayload['tools']) && is_array($openaiPayload['tools'])) {
            $functionDeclarations = [];
            foreach ($openaiPayload['tools'] as $tool) {
                if (($tool['type'] ?? '') === 'function' && isset($tool['function'])) {
                    $fn = $tool['function'];
                    $decl = [
                        'name' => $fn['name'],
                        'description' => $fn['description'] ?? '',
                    ];
                    if (!empty($fn['parameters']) && is_array($fn['parameters'])) {
                        $decl['parameters'] = $this->cleanGeminiSchema($fn['parameters']);
                    }
                    $functionDeclarations[] = $decl;
                }
            }

            if (!empty($functionDeclarations)) {
                $geminiPayload['tools'] = [
                    ['functionDeclarations' => $functionDeclarations]
                ];

                if (isset($openaiPayload['tool_choice'])) {
                    $tc = $openaiPayload['tool_choice'];
                    if ($tc === 'auto') {
                        $geminiPayload['tool_config'] = [
                            'function_calling_config' => ['mode' => 'AUTO']
                        ];
                    } elseif ($tc === 'none') {
                        $geminiPayload['tool_config'] = [
                            'function_calling_config' => ['mode' => 'NONE']
                        ];
                    } elseif ($tc === 'required') {
                        $geminiPayload['tool_config'] = [
                            'function_calling_config' => ['mode' => 'ANY']
                        ];
                    } elseif (is_array($tc) && isset($tc['function']['name'])) {
                        $geminiPayload['tool_config'] = [
                            'function_calling_config' => [
                                'mode' => 'ANY',
                                'allowed_function_names' => [$tc['function']['name']]
                            ]
                        ];
                    }
                }
            }
        }

        return $geminiPayload;
    }

    /**
     * Recursively sanitize JSON schema for Gemini compatibility.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function cleanGeminiSchema(array $schema): array
    {
        unset($schema['$schema']);
        if (isset($schema['additionalProperties']) && is_bool($schema['additionalProperties'])) {
            unset($schema['additionalProperties']);
        }
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $prop) {
                if (is_array($prop)) {
                    $schema['properties'][$key] = $this->cleanGeminiSchema($prop);
                }
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = $this->cleanGeminiSchema($schema['items']);
        }

        return $schema;
    }

    public function parseResponse(array $rawResponse, string $model): array
    {
        $candidate = $rawResponse['candidates'][0] ?? null;
        $parts = $candidate['content']['parts'] ?? [];

        $responseText = '';
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $responseText .= $part['text'];
            }
            if (isset($part['functionCall'])) {
                $fnCall = $part['functionCall'];
                $toolCalls[] = [
                    'id' => 'call_' . bin2hex(random_bytes(9)),
                    'type' => 'function',
                    'function' => [
                        'name' => $fnCall['name'] ?? '',
                        'arguments' => json_encode($fnCall['args'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ],
                ];
            }
        }

        $geminiFinishReason = $candidate['finishReason'] ?? 'STOP';
        if (!empty($toolCalls)) {
            $finishReason = 'tool_calls';
        } else {
            $finishReason = match (strtoupper($geminiFinishReason)) {
                'STOP' => 'stop',
                'MAX_TOKENS' => 'length',
                'SAFETY', 'RECITATION' => 'content_filter',
                default => 'stop',
            };
        }

        $usage = $rawResponse['usageMetadata'] ?? [];
        $promptTokens = (int) ($usage['promptTokenCount'] ?? 0);
        $completionTokens = (int) ($usage['candidatesTokenCount'] ?? 0);
        $totalTokens = (int) ($usage['totalTokenCount'] ?? ($promptTokens + $completionTokens));

        $message = [
            'role' => 'assistant',
            'content' => $responseText !== '' ? $responseText : null,
        ];

        if (!empty($toolCalls)) {
            $message['tool_calls'] = $toolCalls;
        }

        return [
            'id' => 'chatcmpl-gemini-' . bin2hex(random_bytes(10)),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'message' => $message,
                    'finish_reason' => $finishReason,
                ]
            ],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
            ],
        ];
    }

    public function transformStreamChunk(string $rawChunk, string $model): ?string
    {
        $line = trim($rawChunk);
        if ($line === '' || $line === 'event: ping') {
            return null;
        }

        if ($line === 'data: [DONE]') {
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

        $candidate = $data['candidates'][0] ?? null;
        if (!$candidate) {
            return null;
        }

        $parts = $candidate['content']['parts'] ?? [];
        $text = '';
        $toolCalls = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
            if (isset($part['functionCall'])) {
                $fnCall = $part['functionCall'];
                $toolCalls[] = [
                    'index' => count($toolCalls),
                    'id' => 'call_' . bin2hex(random_bytes(9)),
                    'type' => 'function',
                    'function' => [
                        'name' => $fnCall['name'] ?? '',
                        'arguments' => json_encode($fnCall['args'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ],
                ];
            }
        }

        $delta = [];
        if ($text !== '') {
            $delta['content'] = $text;
        }
        if (!empty($toolCalls)) {
            $delta['tool_calls'] = $toolCalls;
        }
        if (empty($delta)) {
            $delta = new \stdClass();
        }

        $finishReason = null;
        if (!empty($toolCalls)) {
            $finishReason = 'tool_calls';
        } elseif (!empty($candidate['finishReason']) && strtoupper($candidate['finishReason']) !== 'STOP') {
            $finishReason = strtolower($candidate['finishReason']);
        }

        $chunk = [
            'id' => 'chatcmpl-chunk-' . substr(md5($model . microtime()), 0, 12),
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'delta' => $delta,
                    'finish_reason' => $finishReason,
                ],
            ],
        ];

        return 'data: ' . json_encode($chunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }
}
