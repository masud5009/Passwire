<?php

namespace App\Services\Gateway\Adapters;

interface ProviderAdapterInterface
{
    /**
     * Return the unique protocol format key (e.g. 'openai', 'anthropic', 'gemini').
     */
    public function getFormat(): string;

    /**
     * Build the target endpoint URL.
     *
     * @param string $baseUrl
     * @param string $model
     * @param bool $isStream
     * @param string|null $apiKey
     * @return string
     */
    public function buildUrl(string $baseUrl, string $model, bool $isStream = false, ?string $apiKey = null): string;

    /**
     * Format HTTP request headers as associative array (for Laravel Http client).
     *
     * @param string $apiKey
     * @param bool $isStream
     * @return array<string, string>
     */
    public function formatHeaders(string $apiKey, bool $isStream = false): array;

    /**
     * Format HTTP headers as indexed list of strings for cURL (e.g. "Header: Value").
     *
     * @param string $apiKey
     * @param bool $isStream
     * @return list<string>
     */
    public function formatCurlHeaders(string $apiKey, bool $isStream = false): array;

    /**
     * Convert standard OpenAI Chat Completions payload into provider-native payload.
     *
     * @param string $model
     * @param array<string, mixed> $openaiPayload
     * @return array<string, mixed>
     */
    public function formatPayload(string $model, array $openaiPayload): array;

    /**
     * Convert provider-native response into standard OpenAI chat.completion response.
     *
     * @param array<string, mixed> $rawResponse
     * @param string $model
     * @return array<string, mixed>
     */
    public function parseResponse(array $rawResponse, string $model): array;

    /**
     * Transform native streaming SSE chunk or line into OpenAI SSE string.
     *
     * @param string $rawChunk
     * @param string $model
     * @return string|null
     */
    public function transformStreamChunk(string $rawChunk, string $model): ?string;
}
