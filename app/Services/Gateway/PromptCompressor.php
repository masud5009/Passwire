<?php

namespace App\Services\Gateway;

class PromptCompressor
{
    /**
     * Common conversational filler phrases for caveman compression.
     *
     * @var array<int, string>
     */
    protected array $cavemanFillers = [
        'could you please',
        'would you kindly',
        'would you please',
        'can you please',
        'will you please',
        'thank you in advance',
        'thanks in advance',
        'thank you very much',
        'thank you so much',
        'thank you',
        'thanks a lot',
        'thanks',
        'please',
        'kindly',
    ];

    /**
     * DataGuardrailService instance.
     */
    protected DataGuardrailService $guardrails;

    public function __construct(?DataGuardrailService $guardrails = null)
    {
        $this->guardrails = $guardrails ?? new DataGuardrailService();
    }

    /**
     * Compress message history based on enabled modes.
     *
     * @param array<int, array{role: string, content: string|array}> $messages
     * @param array<int, string> $modes
     * @return array{messages: array, saved_tokens: int, redacted_pii_count: int}
     */
    public function compress(array $messages, array $modes = ['dedup', 'lite']): array
    {
        if (empty($messages)) {
            return [
                'messages' => [],
                'saved_tokens' => 0,
                'redacted_pii_count' => 0,
            ];
        }

        $redactedPiiCount = 0;

        // 0. Mode 'pii' / 'pii_redaction': Redact sensitive personal data before sending to downstream AI
        if (in_array('pii', $modes, true) || in_array('pii_redaction', $modes, true)) {
            $guardrailResult = $this->guardrails->redactMessages($messages);
            $messages = $guardrailResult['messages'];
            $redactedPiiCount = $guardrailResult['count'];
        }

        $originalJson = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $originalLength = strlen($originalJson);

        // 1. Mode 'dedup': Remove duplicate system role messages with identical content hashes
        if (in_array('dedup', $modes, true)) {
            $messages = $this->dedupSystemMessages($messages);
        }

        $applyLite = in_array('lite', $modes, true);
        $applyCaveman = in_array('caveman', $modes, true);

        // 2 & 3. Mode 'lite' and 'caveman'
        if ($applyLite || $applyCaveman) {
            foreach ($messages as &$message) {
                if (!isset($message['content']) || !is_string($message['content'])) {
                    continue;
                }

                $content = $message['content'];
                $role = $message['role'] ?? '';

                $content = $this->processOutsideCodeBlocks($content, function (string $text) use ($role, $applyLite, $applyCaveman) {
                    // Caveman mode: strip filler phrases from 'user' messages
                    if ($applyCaveman && $role === 'user') {
                        $text = $this->stripFillerPhrases($text);
                    }

                    // Lite mode: collapse 3+ newlines to 2, collapse multiple spaces/tabs to 1
                    if ($applyLite) {
                        $text = preg_replace('/[ \t]+/', ' ', $text);
                        $text = preg_replace("/(\r?\n){3,}/", "\n\n", $text);
                    }

                    return $text;
                });

                if ($applyLite) {
                    $content = trim($content);
                }

                $message['content'] = $content;
            }
            unset($message);
        }

        $compressedJson = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $compressedLength = strlen($compressedJson);

        $savedTokens = max(0, (int) round(($originalLength - $compressedLength) / 4));

        return [
            'messages' => array_values($messages),
            'saved_tokens' => $savedTokens,
            'redacted_pii_count' => $redactedPiiCount,
        ];
    }

    /**
     * Remove duplicate system messages with matching content hashes.
     *
     * @param array<int, array> $messages
     * @return array<int, array>
     */
    protected function dedupSystemMessages(array $messages): array
    {
        $seenSystemHashes = [];
        $result = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';
            if ($role === 'system') {
                $content = $msg['content'] ?? '';
                $contentStr = is_string($content) ? trim($content) : json_encode($content);
                $hash = md5($contentStr);

                if (isset($seenSystemHashes[$hash])) {
                    continue;
                }

                $seenSystemHashes[$hash] = true;
            }

            $result[] = $msg;
        }

        return $result;
    }

    /**
     * Process text outside markdown code blocks (```...```).
     */
    protected function processOutsideCodeBlocks(string $text, callable $callback): string
    {
        $parts = preg_split('/(```[\s\S]*?```)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || count($parts) <= 1) {
            return $callback($text);
        }

        foreach ($parts as $index => &$part) {
            // Even indices are outside code blocks, odd indices are the code blocks
            if ($index % 2 === 0) {
                $part = $callback($part);
            }
        }
        unset($part);

        return implode('', $parts);
    }

    /**
     * Strip conversational filler phrases outside code blocks.
     */
    protected function stripFillerPhrases(string $text): string
    {
        $fillers = $this->cavemanFillers;
        usort($fillers, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($fillers as $filler) {
            $pattern = '/\b' . preg_quote($filler, '/') . '\b[,!\?\.]*/i';
            $text = preg_replace($pattern, '', $text);
        }

        $text = preg_replace('/[ \t]+/', ' ', $text);

        return $text;
    }
}
