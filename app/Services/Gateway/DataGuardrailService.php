<?php

namespace App\Services\Gateway;

class DataGuardrailService
{
    /**
     * Default sensitive data patterns.
     *
     * @var array<string, array{pattern: string, tag: string}>
     */
    protected array $patterns = [
        'private_key' => [
            'pattern' => '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----[\s\S]*?-----END (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/',
            'tag' => '[REDACTED_PRIVATE_KEY]',
        ],
        'jwt' => [
            'pattern' => '/\beyJ[a-zA-Z0-9_\-]{10,}\.eyJ[a-zA-Z0-9_\-]{10,}\.[a-zA-Z0-9_\-]{10,}\b/',
            'tag' => '[REDACTED_JWT_TOKEN]',
        ],
        'api_keys_tokens' => [
            // Matches OpenAI, Anthropic, GitHub, Google, AWS, Slack, and generic API keys
            'pattern' => '/\b(?:sk-[a-zA-Z0-9_\-]{20,}|sk-proj-[a-zA-Z0-9_\-]{20,}|sk-ant-[a-zA-Z0-9_\-]{20,}|ghp_[a-zA-Z0-9]{36}|github_pat_[a-zA-Z0-9_]{50,}|AIzaSy[a-zA-Z0-9_\-]{33}|(?:AKIA|AGPA|AIDA|AROA|AIPA|ANPA|ANVA|ASIA)[A-Z0-9]{16}|xox[baprs]-[a-zA-Z0-9_\-]{10,})\b/',
            'tag' => '[REDACTED_API_KEY]',
        ],
        'credit_cards' => [
            // Visa, Mastercard, Amex, Discover, Diners Club, JCB with optional dashes or spaces
            'pattern' => '/\b(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|2(?:2[2-9][0-9]{12}|[3-6][0-9]{13}|7[01][0-9]{12}|720[0-9]{12})|3[47][0-9]{13}|6(?:011|5[0-9]{2})[0-9]{12}|3(?:0[0-5]|[68][0-9])[0-9]{11}|(?:2131|1800|35\d{3})\d{11}|(?:4[0-9]{3}|5[1-5][0-9]{2}|6011|7[0-9]{3})[\s\-][0-9]{4}[\s\-][0-9]{4}[\s\-][0-9]{4})\b/',
            'tag' => '[REDACTED_CREDIT_CARD]',
        ],
        'ssn' => [
            // Standard US Social Security Numbers
            'pattern' => '/\b(?!000|666|9\d{2})\d{3}-(?!00)\d{2}-(?!0000)\d{4}\b/',
            'tag' => '[REDACTED_SSN]',
        ],
        'emails' => [
            // Standard email address pattern
            'pattern' => '/\b[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}\b/',
            'tag' => '[REDACTED_EMAIL]',
        ],
        'phone_numbers' => [
            // International & domestic phone numbers (e.g. +1-555-123-4567, (555) 123-4567, +8801712345678)
            'pattern' => '/(?<!\w)(?:\+?[1-9]\d{0,2}[\s.-]?)?(?:\(?\d{2,4}\)?[\s.-]?)?\d{3,4}[\s.-]?\d{3,4}(?!\w)/',
            'tag' => '[REDACTED_PHONE]',
        ],
        'passwords' => [
            // Captures password/secret key assignments: password: "secret" or api_secret=abc1234
            'pattern' => '/(?i)\b(?:password|passwd|pwd|api_secret|client_secret)\s*[:=]\s*["\']?([^\s"\'`,;]{6,})["\']?/',
            'tag' => '[REDACTED_PASSWORD]',
        ],
    ];

    /**
     * Redact sensitive personal data in a single text string while preserving code blocks.
     *
     * @param string $text
     * @param array<int, string> $enabledRules (Leave empty for all rules)
     * @return array{text: string, count: int, matches: array<string, int>}
     */
    public function redact(string $text, array $enabledRules = []): array
    {
        if (trim($text) === '') {
            return [
                'text' => $text,
                'count' => 0,
                'matches' => [],
            ];
        }

        $totalRedacted = 0;
        $matchesSummary = [];

        // Split text around markdown code blocks (```...```) to prevent breaking code snippets
        $parts = preg_split('/(```[\s\S]*?```)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            $parts = [$text];
        }

        $rulesToApply = empty($enabledRules)
            ? array_keys($this->patterns)
            : array_intersect($enabledRules, array_keys($this->patterns));

        foreach ($parts as $index => &$part) {
            // Even indices are outside markdown code blocks; odd indices are code blocks
            if ($index % 2 === 0) {
                foreach ($rulesToApply as $ruleName) {
                    $rule = $this->patterns[$ruleName];
                    $pattern = $rule['pattern'];
                    $tag = $rule['tag'];

                    if ($ruleName === 'passwords') {
                        // Special replacement for password fields so the key name is kept
                        $part = preg_replace_callback($pattern, function ($matches) use (&$totalRedacted, &$matchesSummary, $tag) {
                            $totalRedacted++;
                            $matchesSummary['passwords'] = ($matchesSummary['passwords'] ?? 0) + 1;
                            return str_replace($matches[1], $tag, $matches[0]);
                        }, $part);
                    } else {
                        $part = preg_replace_callback($pattern, function ($matches) use (&$totalRedacted, &$matchesSummary, $ruleName, $tag) {
                            // Guard against false positive short numeric strings in phone pattern
                            if ($ruleName === 'phone_numbers') {
                                $digitsOnly = preg_replace('/\D/', '', $matches[0]);
                                if (strlen($digitsOnly) < 7 || strlen($digitsOnly) > 15) {
                                    return $matches[0];
                                }
                            }

                            $totalRedacted++;
                            $matchesSummary[$ruleName] = ($matchesSummary[$ruleName] ?? 0) + 1;
                            return $tag;
                        }, $part);
                    }
                }
            }
        }
        unset($part);

        return [
            'text' => implode('', $parts),
            'count' => $totalRedacted,
            'matches' => $matchesSummary,
        ];
    }

    /**
     * Redact sensitive personal data across an array of chat messages.
     *
     * @param array<int, array{role: string, content: string|array}> $messages
     * @param array<int, string> $enabledRules
     * @return array{messages: array<int, array>, count: int, matches: array<string, int>}
     */
    public function redactMessages(array $messages, array $enabledRules = []): array
    {
        $totalCount = 0;
        $totalMatches = [];

        foreach ($messages as &$msg) {
            if (!isset($msg['content'])) {
                continue;
            }

            if (is_string($msg['content'])) {
                $res = $this->redact($msg['content'], $enabledRules);
                $msg['content'] = $res['text'];
                $totalCount += $res['count'];
                foreach ($res['matches'] as $k => $v) {
                    $totalMatches[$k] = ($totalMatches[$k] ?? 0) + $v;
                }
            } elseif (is_array($msg['content'])) {
                // Support multi-part message contents (e.g. OpenAI text parts)
                foreach ($msg['content'] as &$part) {
                    if (is_array($part) && isset($part['type']) && $part['type'] === 'text' && isset($part['text'])) {
                        $res = $this->redact((string) $part['text'], $enabledRules);
                        $part['text'] = $res['text'];
                        $totalCount += $res['count'];
                        foreach ($res['matches'] as $k => $v) {
                            $totalMatches[$k] = ($totalMatches[$k] ?? 0) + $v;
                        }
                    }
                }
                unset($part);
            }
        }
        unset($msg);

        return [
            'messages' => array_values($messages),
            'count' => $totalCount,
            'matches' => $totalMatches,
        ];
    }
}
