<?php

namespace App\Services\Gateway;

use App\Services\Gateway\Adapters\ProviderAdapterFactory;
use App\Services\Gateway\Adapters\ProviderAdapterInterface;

class FormatTranslator
{
    /**
     * Create a new FormatTranslator instance.
     */
    public function __construct(
        protected ProviderAdapterFactory $adapterFactory
    ) {}

    /**
     * Resolve provider adapter for a specific protocol format.
     */
    public function getAdapter(?string $format): ProviderAdapterInterface
    {
        return $this->adapterFactory->make($format);
    }

    /**
     * Get underlying ProviderAdapterFactory.
     */
    public function getFactory(): ProviderAdapterFactory
    {
        return $this->adapterFactory;
    }

    /**
     * Convert OpenAI Chat Completions payload into Anthropic /v1/messages payload.
     *
     * @param string $model
     * @param array<string, mixed> $openaiPayload
     * @return array<string, mixed>
     */
    public function toAnthropicPayload(string $model, array $openaiPayload): array
    {
        return $this->adapterFactory->make('anthropic')->formatPayload($model, $openaiPayload);
    }

    /**
     * Convert Anthropic JSON response into standard OpenAI chat.completion format.
     *
     * @param array<string, mixed> $raw
     * @param string $model
     * @return array<string, mixed>
     */
    public function fromAnthropicResponse(array $raw, string $model): array
    {
        return $this->adapterFactory->make('anthropic')->parseResponse($raw, $model);
    }

    /**
     * Translate Anthropic streaming SSE lines into OpenAI SSE format.
     *
     * @param string $rawSseLine
     * @param string $model
     * @return string|null
     */
    public function translateAnthropicStreamChunk(string $rawSseLine, string $model): ?string
    {
        return $this->adapterFactory->make('anthropic')->transformStreamChunk($rawSseLine, $model);
    }

    /**
     * Convert OpenAI Chat Completions payload into Google Gemini native payload.
     *
     * @param string $model
     * @param array<string, mixed> $openaiPayload
     * @return array<string, mixed>
     */
    public function toGeminiPayload(string $model, array $openaiPayload): array
    {
        return $this->adapterFactory->make('gemini')->formatPayload($model, $openaiPayload);
    }

    /**
     * Convert Google Gemini JSON response into standard OpenAI chat.completion format.
     *
     * @param array<string, mixed> $raw
     * @param string $model
     * @return array<string, mixed>
     */
    public function fromGeminiResponse(array $raw, string $model): array
    {
        return $this->adapterFactory->make('gemini')->parseResponse($raw, $model);
    }

    /**
     * Translate Google Gemini streaming SSE chunks into OpenAI SSE format.
     *
     * @param string $rawSseLine
     * @param string $model
     * @return string|null
     */
    public function translateGeminiStreamChunk(string $rawSseLine, string $model): ?string
    {
        return $this->adapterFactory->make('gemini')->transformStreamChunk($rawSseLine, $model);
    }
}
