<?php

namespace App\Services\Gateway\Adapters;

class OpenAiAdapter implements ProviderAdapterInterface
{
    public function getFormat(): string
    {
        return 'openai';
    }

    public function buildUrl(string $baseUrl, string $model, bool $isStream = false, ?string $apiKey = null): string
    {
        $cleanBase = rtrim($baseUrl, '/');
        if (str_ends_with($cleanBase, '/chat/completions')) {
            return $cleanBase;
        }

        return $cleanBase . '/chat/completions';
    }

    public function formatHeaders(string $apiKey, bool $isStream = false): array
    {
        return [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => $isStream ? 'text/event-stream' : 'application/json',
        ];
    }

    public function formatCurlHeaders(string $apiKey, bool $isStream = false): array
    {
        return [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: ' . ($isStream ? 'text/event-stream' : 'application/json'),
        ];
    }

    public function formatPayload(string $model, array $openaiPayload): array
    {
        $payload = $openaiPayload;
        $payload['model'] = $model;

        return $payload;
    }

    public function parseResponse(array $rawResponse, string $model): array
    {
        if (!isset($rawResponse['model']) || empty($rawResponse['model'])) {
            $rawResponse['model'] = $model;
        }

        return $rawResponse;
    }

    public function transformStreamChunk(string $rawChunk, string $model): ?string
    {
        // OpenAI SSE chunks are already in standard OpenAI format
        return $rawChunk;
    }
}
