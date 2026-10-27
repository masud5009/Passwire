<?php

namespace App\Services\Gateway\Adapters;

class ProviderAdapterFactory
{
    /**
     * @var array<string, ProviderAdapterInterface>
     */
    protected array $adapters = [];

    public function __construct(
        OpenAiAdapter $openAiAdapter,
        AnthropicAdapter $anthropicAdapter,
        GeminiAdapter $geminiAdapter
    ) {
        $this->register($openAiAdapter);
        $this->register($anthropicAdapter);
        $this->register($geminiAdapter);
    }

    /**
     * Register a provider adapter instance.
     */
    public function register(ProviderAdapterInterface $adapter): void
    {
        $this->adapters[$adapter->getFormat()] = $adapter;
    }

    /**
     * Resolve provider adapter by format string.
     * Defaults to OpenAiAdapter if unknown or null.
     */
    public function make(?string $format): ProviderAdapterInterface
    {
        $key = strtolower(trim($format ?? 'openai'));

        return $this->adapters[$key] ?? $this->adapters['openai'];
    }

    /**
     * Return list of all supported format keys.
     *
     * @return list<string>
     */
    public function getSupportedFormats(): array
    {
        return array_keys($this->adapters);
    }
}
