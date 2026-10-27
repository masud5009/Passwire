<?php

namespace Tests\Unit\Adapters;

use App\Services\Gateway\Adapters\AnthropicAdapter;
use PHPUnit\Framework\TestCase;

class AnthropicAdapterTest extends TestCase
{
    protected AnthropicAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new AnthropicAdapter();
    }

    public function test_formats_tools_and_tool_choice_correctly(): void
    {
        $payload = [
            'messages' => [
                ['role' => 'user', 'content' => 'What is the weather in Tokyo?'],
            ],
            'tools' => [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_weather',
                        'description' => 'Get weather for city',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'location' => ['type' => 'string'],
                            ],
                            'required' => ['location'],
                        ],
                    ],
                ],
            ],
            'tool_choice' => 'required',
        ];

        $formatted = $this->adapter->formatPayload('claude-3-5-sonnet', $payload);

        $this->assertArrayHasKey('tools', $formatted);
        $this->assertCount(1, $formatted['tools']);
        $this->assertEquals('get_weather', $formatted['tools'][0]['name']);
        $this->assertEquals('Get weather for city', $formatted['tools'][0]['description']);
        $this->assertEquals(['type' => 'any'], $formatted['tool_choice']);
    }

    public function test_formats_tool_calls_and_tool_results_history(): void
    {
        $payload = [
            'messages' => [
                ['role' => 'user', 'content' => 'What is the weather?'],
                [
                    'role' => 'assistant',
                    'content' => 'Checking weather...',
                    'tool_calls' => [
                        [
                            'id' => 'call_abc123',
                            'type' => 'function',
                            'function' => [
                                'name' => 'get_weather',
                                'arguments' => '{"location":"Tokyo"}',
                            ],
                        ],
                    ],
                ],
                [
                    'role' => 'tool',
                    'tool_call_id' => 'call_abc123',
                    'content' => '{"temperature": 22}',
                ],
            ],
        ];

        $formatted = $this->adapter->formatPayload('claude-3-5-sonnet', $payload);

        $messages = $formatted['messages'];
        $this->assertCount(3, $messages);

        // Assistant turn contains tool_use block
        $this->assertEquals('assistant', $messages[1]['role']);
        $this->assertIsArray($messages[1]['content']);
        $this->assertEquals('tool_use', $messages[1]['content'][1]['type']);
        $this->assertEquals('call_abc123', $messages[1]['content'][1]['id']);
        $this->assertEquals('get_weather', $messages[1]['content'][1]['name']);
        $this->assertEquals(['location' => 'Tokyo'], $messages[1]['content'][1]['input']);

        // Tool turn mapped to user with tool_result block
        $this->assertEquals('user', $messages[2]['role']);
        $this->assertIsArray($messages[2]['content']);
        $this->assertEquals('tool_result', $messages[2]['content'][0]['type']);
        $this->assertEquals('call_abc123', $messages[2]['content'][0]['tool_use_id']);
    }

    public function test_parses_tool_use_response_into_openai_tool_calls(): void
    {
        $rawAnthropic = [
            'id' => 'msg_01X9Z',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'I am calling the weather tool.',
                ],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_01ABC',
                    'name' => 'get_weather',
                    'input' => ['location' => 'Tokyo'],
                ],
            ],
            'stop_reason' => 'tool_use',
            'usage' => [
                'input_tokens' => 25,
                'output_tokens' => 18,
            ],
        ];

        $parsed = $this->adapter->parseResponse($rawAnthropic, 'claude-3-5-sonnet');

        $this->assertEquals('tool_calls', $parsed['choices'][0]['finish_reason']);
        $message = $parsed['choices'][0]['message'];
        $this->assertEquals('I am calling the weather tool.', $message['content']);
        $this->assertArrayHasKey('tool_calls', $message);
        $this->assertCount(1, $message['tool_calls']);
        $this->assertEquals('toolu_01ABC', $message['tool_calls'][0]['id']);
        $this->assertEquals('get_weather', $message['tool_calls'][0]['function']['name']);
        $this->assertJsonStringEqualsJsonString(
            json_encode(['location' => 'Tokyo']),
            $message['tool_calls'][0]['function']['arguments']
        );
    }

    public function test_transforms_streaming_tool_call_chunks(): void
    {
        // 1. Tool block start
        $startChunk = 'data: ' . json_encode([
            'type' => 'content_block_start',
            'index' => 0,
            'content_block' => [
                'type' => 'tool_use',
                'id' => 'toolu_start_123',
                'name' => 'fetch_stock_quote',
            ],
        ]);

        $translatedStart = $this->adapter->transformStreamChunk($startChunk, 'claude-3-5-sonnet');
        $this->assertNotNull($translatedStart);
        $this->assertStringStartsWith('data: ', $translatedStart);
        $decodedStart = json_decode(substr(trim($translatedStart), 6), true);
        $this->assertEquals('fetch_stock_quote', $decodedStart['choices'][0]['delta']['tool_calls'][0]['function']['name']);

        // 2. Input json delta
        $deltaChunk = 'data: ' . json_encode([
            'type' => 'content_block_delta',
            'index' => 0,
            'delta' => [
                'type' => 'input_json_delta',
                'partial_json' => '{"ticker": "AAPL"}',
            ],
        ]);

        $translatedDelta = $this->adapter->transformStreamChunk($deltaChunk, 'claude-3-5-sonnet');
        $this->assertNotNull($translatedDelta);
        $decodedDelta = json_decode(substr(trim($translatedDelta), 6), true);
        $this->assertEquals('{"ticker": "AAPL"}', $decodedDelta['choices'][0]['delta']['tool_calls'][0]['function']['arguments']);

        // 3. Message delta finish reason
        $stopChunk = 'data: ' . json_encode([
            'type' => 'message_delta',
            'delta' => [
                'stop_reason' => 'tool_use',
            ],
        ]);

        $translatedStop = $this->adapter->transformStreamChunk($stopChunk, 'claude-3-5-sonnet');
        $this->assertNotNull($translatedStop);
        $decodedStop = json_decode(substr(trim($translatedStop), 6), true);
        $this->assertEquals('tool_calls', $decodedStop['choices'][0]['finish_reason']);
    }
}
