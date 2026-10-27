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

        foreach ($openaiPayload['messages'] ?? [] as $msg) {
            $role = $msg['role'] ?? 'user';
            $rawContent = $msg['content'] ?? '';

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

            if ($role === 'system') {
                if (trim($text) !== '') {
                    $systemParts[] = ['text' => trim($text)];
                }
                continue;
            }

            // Map role: user -> 'user', assistant -> 'model'
            $geminiRole = ($role === 'assistant') ? 'model' : 'user';

            // Gemini forbids consecutive turns with the same role. Combine parts if necessary.
            $lastIndex = count($contents) - 1;
            if ($lastIndex >= 0 && $contents[$lastIndex]['role'] === $geminiRole) {
                $contents[$lastIndex]['parts'][] = ['text' => $text];
            } else {
                $contents[] = [
                    'role' => $geminiRole,
                    'parts' => [
                        ['text' => $text]
                    ],
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

        return $geminiPayload;
    }

    public function parseResponse(array $rawResponse, string $model): array
    {
        $candidate = $rawResponse['candidates'][0] ?? null;
        $parts = $candidate['content']['parts'] ?? [];

        $responseText = '';
        foreach ($parts as $part) {
            $responseText .= $part['text'] ?? '';
        }

        $geminiFinishReason = $candidate['finishReason'] ?? 'STOP';
        $finishReason = match (strtoupper($geminiFinishReason)) {
            'STOP' => 'stop',
            'MAX_TOKENS' => 'length',
            'SAFETY', 'RECITATION' => 'content_filter',
            default => 'stop',
        };

        $usage = $rawResponse['usageMetadata'] ?? [];
        $promptTokens = (int) ($usage['promptTokenCount'] ?? 0);
        $completionTokens = (int) ($usage['candidatesTokenCount'] ?? 0);
        $totalTokens = (int) ($usage['totalTokenCount'] ?? ($promptTokens + $completionTokens));

        return [
            'id' => 'chatcmpl-gemini-' . bin2hex(random_bytes(10)),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $responseText,
                    ],
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
        foreach ($parts as $part) {
            $text .= $part['text'] ?? '';
        }

        $finishReason = null;
        if (!empty($candidate['finishReason']) && strtoupper($candidate['finishReason']) !== 'STOP') {
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
                    'delta' => $text !== '' ? ['content' => $text] : new stdClass(),
                    'finish_reason' => $finishReason,
                ],
            ],
        ];

        return 'data: ' . json_encode($chunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }
}
