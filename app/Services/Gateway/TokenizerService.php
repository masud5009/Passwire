<?php

namespace App\Services\Gateway;

class TokenizerService
{
    /**
     * Estimate token count of a text string.
     * Uses calibrated character, word, and Unicode boundary analysis.
     */
    public function countTextTokens(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $charCount = mb_strlen($text, 'UTF-8');
        $byteCount = strlen($text);

        // 1. Pure ASCII text (English, code, numbers, punctuation)
        if ($byteCount === $charCount) {
            $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
            $wordCount = is_array($words) ? count($words) : 0;

            if ($wordCount === 0) {
                return max(1, (int) ceil($charCount / 4));
            }

            // In typical English / code text:
            // ~1 token per 3.8 - 4 characters, or ~1.33 tokens per word (due to subword splitting & punctuation)
            $byChars = (int) ceil($charCount / 4);
            $byWords = (int) ceil($wordCount * 1.33);

            return max(1, (int) round(($byChars + $byWords) / 2));
        }

        // 2. Multilingual / UTF-8 multi-byte text (Bengali, Hindi, Arabic, CJK, Emoji)
        // Count ASCII characters vs non-ASCII characters
        $asciiMatches = preg_match_all('/[a-zA-Z0-9\s\p{P}]/u', $text);
        $asciiChars = is_int($asciiMatches) ? $asciiMatches : 0;
        $multiByteChars = max(0, $charCount - $asciiChars);

        // ASCII averages ~4 chars per token; multi-byte (like Indic / CJK) averages ~1.5 - 2 chars per token
        $asciiTokens = ceil($asciiChars / 4);
        $multiByteTokens = ceil($multiByteChars / 1.75);

        return max(1, (int) ($asciiTokens + $multiByteTokens));
    }

    /**
     * Estimate total prompt tokens from an OpenAI chat completion payload.
     * Follows OpenAI's official chat format token counting guidelines:
     * - Message formatting overhead (<|im_start|>{role}\n{content}<|im_end|>\n = ~3-4 tokens)
     * - 3 tokens priming the assistant response prefix (<|start|>assistant<|message|>)
     * - Tools and function definitions token overhead
     */
    public function estimatePromptTokens(array $payload): int
    {
        // Priming token overhead: every reply is primed with <|start|>assistant<|message|>
        $tokens = 3;

        $messages = $payload['messages'] ?? [];
        if (!is_array($messages)) {
            return $tokens;
        }

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            // Message boundary tokens (<|im_start|>{role}\n ... <|im_end|>\n)
            $tokens += 3;

            // Role token overhead
            $role = (string) ($message['role'] ?? '');
            if ($role !== '') {
                $tokens += (int) ceil(strlen($role) / 4);
            }

            // Message content
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $tokens += $this->countTextTokens($content);
            } elseif (is_array($content)) {
                foreach ($content as $part) {
                    if (is_array($part)) {
                        $partType = $part['type'] ?? '';
                        if ($partType === 'text' && isset($part['text'])) {
                            $tokens += $this->countTextTokens((string) $part['text']);
                        } elseif ($partType === 'image_url') {
                            $tokens += 85; // Standard OpenAI low-detail image token cost
                        }
                    } elseif (is_string($part)) {
                        $tokens += $this->countTextTokens($part);
                    }
                }
            }

            // Author name (if present, costs 1 additional token)
            if (!empty($message['name']) && is_string($message['name'])) {
                $tokens += 1 + (int) ceil(strlen($message['name']) / 4);
            }

            // Tool calls in assistant message
            if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) {
                foreach ($message['tool_calls'] as $tc) {
                    $tokens += 4;
                    $fn = $tc['function'] ?? [];
                    if (!empty($fn['name']) && is_string($fn['name'])) {
                        $tokens += (int) ceil(strlen($fn['name']) / 4);
                    }
                    if (!empty($fn['arguments']) && is_string($fn['arguments'])) {
                        $tokens += $this->countTextTokens($fn['arguments']);
                    }
                }
            }
        }

        // Tools and function definitions in payload
        if (!empty($payload['tools']) && is_array($payload['tools'])) {
            $tokens += 8;
            foreach ($payload['tools'] as $tool) {
                $fn = $tool['function'] ?? [];
                if (!empty($fn['name']) && is_string($fn['name'])) {
                    $tokens += (int) ceil(strlen($fn['name']) / 4);
                }
                if (!empty($fn['description']) && is_string($fn['description'])) {
                    $tokens += $this->countTextTokens($fn['description']);
                }
                if (!empty($fn['parameters'])) {
                    $tokens += $this->countTextTokens(json_encode($fn['parameters']));
                }
            }
        }

        return max(1, $tokens);
    }

    /**
     * Estimate completion tokens from accumulated assistant response text and tool calls.
     */
    public function estimateCompletionTokens(string $completionText, array $toolCalls = []): int
    {
        $tokens = 0;
        if (trim($completionText) !== '') {
            $tokens += $this->countTextTokens($completionText);
        }

        if (!empty($toolCalls)) {
            foreach ($toolCalls as $tc) {
                $tokens += 4;
                $fn = $tc['function'] ?? [];
                if (!empty($fn['name']) && is_string($fn['name'])) {
                    $tokens += (int) ceil(strlen($fn['name']) / 4);
                }
                if (!empty($fn['arguments']) && is_string($fn['arguments'])) {
                    $tokens += $this->countTextTokens($fn['arguments']);
                }
            }
        }

        return max(1, $tokens);
    }

    /**
     * Parse raw SSE lines from any upstream provider (OpenAI, Anthropic, Gemini)
     * and extract accumulated text content, tool calls, finish reasons, and usage metrics.
     *
     * @param string $line Raw SSE line (e.g. "data: {...}")
     * @param string $format Upstream format ('openai', 'anthropic', 'gemini')
     * @param array $usageTracker Array with keys: prompt_tokens, completion_tokens, total_tokens
     * @param string $accumulatedText In-out accumulated assistant text
     * @param bool $usageChunkFound In-out flag whether a standard OpenAI usage chunk was detected
     * @param array $accumulatedToolCalls In-out accumulated assistant tool calls
     * @param string|null $finishReason In-out detected finish reason
     */
    public function parseStreamChunk(
        string $line,
        string $format,
        array &$usageTracker,
        string &$accumulatedText,
        bool &$usageChunkFound = false,
        array &$accumulatedToolCalls = [],
        ?string &$finishReason = null
    ): void {
        $trimmed = trim($line);
        if ($trimmed === '' || str_contains($trimmed, '[DONE]')) {
            return;
        }

        $jsonStr = $trimmed;
        if (str_starts_with($trimmed, 'data: ')) {
            $jsonStr = trim(substr($trimmed, 6));
        }

        $data = json_decode($jsonStr, true);
        if (!is_array($data)) {
            return;
        }

        if ($format === 'openai') {
            // 1. Accumulate generated text
            if (isset($data['choices'][0]['delta']['content']) && is_string($data['choices'][0]['delta']['content'])) {
                $accumulatedText .= $data['choices'][0]['delta']['content'];
            }

            // 2. Track finish_reason
            if (!empty($data['choices'][0]['finish_reason'])) {
                $finishReason = (string) $data['choices'][0]['finish_reason'];
            }

            // 3. Track tool_calls delta
            if (!empty($data['choices'][0]['delta']['tool_calls']) && is_array($data['choices'][0]['delta']['tool_calls'])) {
                foreach ($data['choices'][0]['delta']['tool_calls'] as $tc) {
                    $idx = $tc['index'] ?? count($accumulatedToolCalls);
                    if (!isset($accumulatedToolCalls[$idx])) {
                        $accumulatedToolCalls[$idx] = [
                            'id' => $tc['id'] ?? ('call_' . bin2hex(random_bytes(6))),
                            'type' => $tc['type'] ?? 'function',
                            'function' => [
                                'name' => $tc['function']['name'] ?? '',
                                'arguments' => $tc['function']['arguments'] ?? '',
                            ],
                        ];
                    } else {
                        if (!empty($tc['id'])) {
                            $accumulatedToolCalls[$idx]['id'] = $tc['id'];
                        }
                        if (!empty($tc['type'])) {
                            $accumulatedToolCalls[$idx]['type'] = $tc['type'];
                        }
                        if (!empty($tc['function']['name'])) {
                            $accumulatedToolCalls[$idx]['function']['name'] .= $tc['function']['name'];
                        }
                        if (isset($tc['function']['arguments'])) {
                            $accumulatedToolCalls[$idx]['function']['arguments'] .= $tc['function']['arguments'];
                        }
                    }
                }
            }

            // 4. Read usage chunk if provided (via stream_options.include_usage)
            if (isset($data['usage']) && is_array($data['usage'])) {
                $usageChunkFound = true;
                if (isset($data['usage']['prompt_tokens']) && is_numeric($data['usage']['prompt_tokens'])) {
                    $usageTracker['prompt_tokens'] = (int) $data['usage']['prompt_tokens'];
                }
                if (isset($data['usage']['completion_tokens']) && is_numeric($data['usage']['completion_tokens'])) {
                    $usageTracker['completion_tokens'] = (int) $data['usage']['completion_tokens'];
                }
                if (isset($data['usage']['total_tokens']) && is_numeric($data['usage']['total_tokens'])) {
                    $usageTracker['total_tokens'] = (int) $data['usage']['total_tokens'];
                }
            }
        } elseif ($format === 'anthropic') {
            // Anthropic: message_start gives input_tokens (prompt tokens)
            if (($data['type'] ?? '') === 'message_start' && isset($data['message']['usage']['input_tokens'])) {
                $usageTracker['prompt_tokens'] = (int) $data['message']['usage']['input_tokens'];
            }

            // Anthropic: content_block_delta gives text delta
            if (($data['type'] ?? '') === 'content_block_delta' && isset($data['delta']['text'])) {
                $accumulatedText .= (string) $data['delta']['text'];
            }

            // Anthropic: message_delta gives output_tokens and stop_reason
            if (($data['type'] ?? '') === 'message_delta') {
                if (isset($data['usage']['output_tokens'])) {
                    $usageTracker['completion_tokens'] = (int) $data['usage']['output_tokens'];
                    $prompt = $usageTracker['prompt_tokens'] ?? 0;
                    $usageTracker['total_tokens'] = $prompt + $usageTracker['completion_tokens'];
                }
                if (isset($data['delta']['stop_reason'])) {
                    $sr = (string) $data['delta']['stop_reason'];
                    $finishReason = $sr === 'tool_use' ? 'tool_calls' : ($sr === 'end_turn' ? 'stop' : $sr);
                }
            }
        } elseif ($format === 'gemini') {
            // Gemini: text content
            if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                $accumulatedText .= (string) $data['candidates'][0]['content']['parts'][0]['text'];
            }

            // Gemini: finishReason
            if (isset($data['candidates'][0]['finishReason'])) {
                $fr = (string) $data['candidates'][0]['finishReason'];
                $finishReason = $fr === 'STOP' ? 'stop' : strtolower($fr);
            }

            // Gemini: usageMetadata
            if (isset($data['usageMetadata']) && is_array($data['usageMetadata'])) {
                if (isset($data['usageMetadata']['promptTokenCount']) && is_numeric($data['usageMetadata']['promptTokenCount'])) {
                    $usageTracker['prompt_tokens'] = (int) $data['usageMetadata']['promptTokenCount'];
                }
                if (isset($data['usageMetadata']['candidatesTokenCount']) && is_numeric($data['usageMetadata']['candidatesTokenCount'])) {
                    $usageTracker['completion_tokens'] = (int) $data['usageMetadata']['candidatesTokenCount'];
                }
                if (isset($data['usageMetadata']['totalTokenCount']) && is_numeric($data['usageMetadata']['totalTokenCount'])) {
                    $usageTracker['total_tokens'] = (int) $data['usageMetadata']['totalTokenCount'];
                }
            }
        }
    }

    /**
     * Finalize accurate token metrics:
     * - Uses provider-reported usage if available.
     * - Otherwise estimates prompt and completion tokens via tokenizer analysis.
     *
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    public function resolveStreamTokens(array $usageTracker, array $payload, string $accumulatedText, array $toolCalls = []): array
    {
        $promptTokens = $usageTracker['prompt_tokens'] ?? null;
        if ($promptTokens === null || $promptTokens <= 0) {
            $promptTokens = $this->estimatePromptTokens($payload);
        }

        $completionTokens = $usageTracker['completion_tokens'] ?? null;
        if ($completionTokens === null || $completionTokens <= 0) {
            $completionTokens = $this->estimateCompletionTokens($accumulatedText, $toolCalls);
        }

        $totalTokens = $usageTracker['total_tokens'] ?? ($promptTokens + $completionTokens);
        if ($totalTokens <= 0) {
            $totalTokens = $promptTokens + $completionTokens;
        }

        return [
            'prompt_tokens' => max(1, (int) $promptTokens),
            'completion_tokens' => max(1, (int) $completionTokens),
            'total_tokens' => max(1, (int) $totalTokens),
        ];
    }

    /**
     * Format a standard OpenAI streaming usage chunk (emitted when stream_options.include_usage is requested).
     */
    public function formatStreamUsageChunk(string $model, int $promptTokens, int $completionTokens, int $totalTokens): string
    {
        $chunk = [
            'id' => 'chatcmpl-usage-' . bin2hex(random_bytes(6)),
            'object' => 'chat.completion.chunk',
            'created' => time(),
            'model' => $model,
            'choices' => [],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
            ],
        ];

        return 'data: ' . json_encode($chunk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }
}
