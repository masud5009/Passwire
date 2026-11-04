<?php

namespace Tests\Unit;

use App\Services\Gateway\TokenizerService;
use PHPUnit\Framework\TestCase;

class TokenizerStreamUsageTest extends TestCase
{
    private TokenizerService $tokenizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenizer = new TokenizerService();
    }

    public function test_openai_stream_parsing_with_usage_chunk(): void
    {
        $usageTracker = ['prompt_tokens' => null, 'completion_tokens' => null, 'total_tokens' => null];
        $accumulatedText = '';
        $usageChunkFound = false;

        // 1. Text delta chunk
        $chunk1 = 'data: {"id":"chatcmpl-123","object":"chat.completion.chunk","choices":[{"delta":{"content":"Hello world!"},"index":0,"finish_reason":null}]}';
        $this->tokenizer->parseStreamChunk($chunk1, 'openai', $usageTracker, $accumulatedText, $usageChunkFound);

        $this->assertEquals('Hello world!', $accumulatedText);
        $this->assertFalse($usageChunkFound);
        $this->assertNull($usageTracker['prompt_tokens']);

        // 2. OpenAI usage chunk (from stream_options.include_usage: true)
        $chunk2 = 'data: {"id":"chatcmpl-123","object":"chat.completion.chunk","choices":[],"usage":{"prompt_tokens":18,"completion_tokens":45,"total_tokens":63}}';
        $this->tokenizer->parseStreamChunk($chunk2, 'openai', $usageTracker, $accumulatedText, $usageChunkFound);

        $this->assertTrue($usageChunkFound);
        $this->assertEquals(18, $usageTracker['prompt_tokens']);
        $this->assertEquals(45, $usageTracker['completion_tokens']);
        $this->assertEquals(63, $usageTracker['total_tokens']);

        // 3. DONE line
        $this->tokenizer->parseStreamChunk('data: [DONE]', 'openai', $usageTracker, $accumulatedText, $usageChunkFound);
        $this->assertEquals('Hello world!', $accumulatedText);
    }

    public function test_anthropic_stream_parsing_with_usage_events(): void
    {
        $usageTracker = ['prompt_tokens' => null, 'completion_tokens' => null, 'total_tokens' => null];
        $accumulatedText = '';
        $usageChunkFound = false;

        // 1. message_start containing input_tokens
        $startChunk = 'data: {"type":"message_start","message":{"id":"msg_123","type":"message","role":"assistant","usage":{"input_tokens":25,"output_tokens":1}}}';
        $this->tokenizer->parseStreamChunk($startChunk, 'anthropic', $usageTracker, $accumulatedText, $usageChunkFound);

        $this->assertEquals(25, $usageTracker['prompt_tokens']);

        // 2. content_block_delta containing text
        $deltaChunk = 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Bonjour le monde!"}}';
        $this->tokenizer->parseStreamChunk($deltaChunk, 'anthropic', $usageTracker, $accumulatedText, $usageChunkFound);

        $this->assertEquals('Bonjour le monde!', $accumulatedText);

        // 3. message_delta containing final output_tokens
        $msgDeltaChunk = 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":50}}';
        $this->tokenizer->parseStreamChunk($msgDeltaChunk, 'anthropic', $usageTracker, $accumulatedText, $usageChunkFound);

        $this->assertEquals(50, $usageTracker['completion_tokens']);
        $this->assertEquals(75, $usageTracker['total_tokens']); // 25 + 50
    }

    public function test_gemini_stream_parsing_with_usage_metadata(): void
    {
        $usageTracker = ['prompt_tokens' => null, 'completion_tokens' => null, 'total_tokens' => null];
        $accumulatedText = '';
        $usageChunkFound = false;

        // Gemini streaming SSE chunk with usageMetadata
        $geminiChunk = 'data: {"candidates":[{"content":{"parts":[{"text":"Namaste"}],"role":"model"}}],"usageMetadata":{"promptTokenCount":12,"candidatesTokenCount":30,"totalTokenCount":42}}';
        $this->tokenizer->parseStreamChunk($geminiChunk, 'gemini', $usageTracker, $accumulatedText, $usageChunkFound);

        $this->assertEquals('Namaste', $accumulatedText);
        $this->assertEquals(12, $usageTracker['prompt_tokens']);
        $this->assertEquals(30, $usageTracker['completion_tokens']);
        $this->assertEquals(42, $usageTracker['total_tokens']);
    }

    public function test_resolve_stream_tokens_prefers_provider_usage(): void
    {
        $usageTracker = [
            'prompt_tokens' => 20,
            'completion_tokens' => 40,
            'total_tokens' => 60,
        ];
        $payload = ['messages' => [['role' => 'user', 'content' => 'Short message']]];
        $accumulatedText = 'Short reply';

        $resolved = $this->tokenizer->resolveStreamTokens($usageTracker, $payload, $accumulatedText);

        $this->assertEquals(20, $resolved['prompt_tokens']);
        $this->assertEquals(40, $resolved['completion_tokens']);
        $this->assertEquals(60, $resolved['total_tokens']);
    }

    public function test_resolve_stream_tokens_falls_back_to_tokenizer_estimation_when_missing(): void
    {
        // When provider omitted usage
        $usageTracker = ['prompt_tokens' => null, 'completion_tokens' => null, 'total_tokens' => null];
        $payload = [
            'messages' => [
                ['role' => 'system', 'content' => 'You are an AI assistant.'],
                ['role' => 'user', 'content' => 'Explain quantum mechanics in simple terms.'],
            ],
        ];
        $accumulatedText = 'Quantum mechanics is the study of matter and energy at the atomic and subatomic level.';

        $resolved = $this->tokenizer->resolveStreamTokens($usageTracker, $payload, $accumulatedText);

        // Verify tokens are non-zero, realistic, and not chunk count
        $this->assertGreaterThan(10, $resolved['prompt_tokens']);
        $this->assertGreaterThan(10, $resolved['completion_tokens']);
        $this->assertEquals($resolved['prompt_tokens'] + $resolved['completion_tokens'], $resolved['total_tokens']);
    }

    public function test_format_stream_usage_chunk(): void
    {
        $formatted = $this->tokenizer->formatStreamUsageChunk('gpt-4o', 15, 30, 45);

        $this->assertStringStartsWith('data: ', $formatted);
        $this->assertStringEndsWith("\n\n", $formatted);

        $jsonStr = trim(substr($formatted, 6));
        $decoded = json_decode($jsonStr, true);

        $this->assertIsArray($decoded);
        $this->assertEquals('chat.completion.chunk', $decoded['object']);
        $this->assertEquals('gpt-4o', $decoded['model']);
        $this->assertEmpty($decoded['choices']);
        $this->assertEquals([
            'prompt_tokens' => 15,
            'completion_tokens' => 30,
            'total_tokens' => 45,
        ], $decoded['usage']);
    }
}

