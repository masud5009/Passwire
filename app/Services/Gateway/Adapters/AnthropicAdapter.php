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
            $content = $msg['content'] ?? '';

            if ($role === 'system') {
                if (is_string($content)) {
                    $systemParts[] = trim($content);
                } elseif (is_array($content)) {
                    $textParts = [];
                    foreach ($content as $item) {
                        if (is_array($item) && isset($item['text'])) {
                            $textParts[] = $item['text'];
                        }
                    }
                    $systemParts[] = implode("\n", $textParts);
                }
                continue;
            }

            // Map role for Anthropic ('user' or 'assistant')
            $mappedRole = ($role === 'assistant') ? 'assistant' : 'user';

            // Anthropic strictly requires alternating roles.
            $lastIndex = count($anthropicMessages) - 1;
            if ($lastIndex >= 0 && $anthropicMessages[$lastIndex]['role'] === $mappedRole) {
                $prev = $anthropicMessages[$lastIndex]['content'];
                if (is_string($prev) && is_string($content)) {
                    $anthropicMessages[$lastIndex]['content'] = $prev . "\n\n" . $content;
                } elseif (is_array($prev) && is_array($content)) {
                    $anthropicMessages[$lastIndex]['content'] = array_merge($prev, $content);
                } else {
                    $prevStr = is_string($prev) ? $prev : json_encode($prev);
                    $currStr = is_string($content) ? $content : json_encode($content);
                    $anthropicMessages[$lastIndex]['content'] = $prevStr . "\n\n" . $currStr;
                }
            } else {
                $anthropicMessages[] = [
                    'role' => $mappedRole,
                    'content' => $content,
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

        return $payload;
    }

    public function parseResponse(array $rawResponse, string $model): array
    {
        $content = '';
        if (isset($rawResponse['content'])) {
            if (is_array($rawResponse['content'])) {
                foreach ($rawResponse['content'] as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'text') {
                        $content .= $block['text'] ?? '';
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
            'end_turn' => 'stop',
            'max_tokens' => 'length',
            'stop_sequence' => 'stop',
            default => 'stop',
        };

        $promptTokens = (int) ($rawResponse['usage']['input_tokens'] ?? 0);
        $completionTokens = (int) ($rawResponse['usage']['output_tokens'] ?? 0);

        return [
            'id' => $rawResponse['id'] ?? ('chatcmpl-' . bin2hex(random_bytes(12))),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
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

        if ($type === 'content_block_delta') {
            $text = $data['delta']['text'] ?? '';
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

        return null;
    }
}
