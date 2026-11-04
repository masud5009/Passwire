<?php

namespace Tests\Unit;

use App\Services\Gateway\TokenizerService;
use PHPUnit\Framework\TestCase;

class TokenizerServiceTest extends TestCase
{
    public function test_count_text_tokens_for_english(): void
    {
        $tokenizer = new TokenizerService();

        $this->assertEquals(0, $tokenizer->countTextTokens(''));

        // Short English text: ~2 tokens
        $tokens = $tokenizer->countTextTokens('Hello world');
        $this->assertGreaterThanOrEqual(2, $tokens);
        $this->assertLessThanOrEqual(4, $tokens);

        // Paragraph of ~100 words: ~100-140 tokens
        $text = str_repeat('The quick brown fox jumps over the lazy dog. ', 10);
        $pTokens = $tokenizer->countTextTokens($text);
        $this->assertGreaterThan(50, $pTokens);
        $this->assertLessThan(200, $pTokens);
    }

    public function test_count_text_tokens_for_multilingual(): void
    {
        $tokenizer = new TokenizerService();

        // Bengali text
        $bengaliText = 'গেটওয়ে টোকেন গণনা ও প্রম্পট প্রসেসিং';
        $tokens = $tokenizer->countTextTokens($bengaliText);

        $this->assertGreaterThan(5, $tokens);
    }

    public function test_estimate_prompt_tokens(): void
    {
        $tokenizer = new TokenizerService();

        $payload = [
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => 'What is the capital of France?'],
            ],
        ];

        $tokens = $tokenizer->estimatePromptTokens($payload);

        // Expected: ~20-30 tokens including priming and formatting
        $this->assertGreaterThanOrEqual(15, $tokens);
        $this->assertLessThan(50, $tokens);
    }

    public function test_estimate_completion_tokens(): void
    {
        $tokenizer = new TokenizerService();

        $completion = "Paris is the capital of France, known for its art, gastronomy, and culture.";
        $tokens = $tokenizer->estimateCompletionTokens($completion);

        $this->assertGreaterThanOrEqual(10, $tokens);
        $this->assertLessThan(30, $tokens);

        // Empty response defaults to 1 token
        $this->assertEquals(1, $tokenizer->estimateCompletionTokens(''));
    }
}

