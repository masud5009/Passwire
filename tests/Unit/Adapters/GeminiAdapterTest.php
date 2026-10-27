<?php

namespace Tests\Unit\Adapters;

use App\Services\Gateway\Adapters\GeminiAdapter;
use PHPUnit\Framework\TestCase;

class GeminiAdapterTest extends TestCase
{
    protected GeminiAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new GeminiAdapter();
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
            'tool_choice' => 'auto',
        ];

        $formatted = $this->adapter->formatPayload('gemini-2.0-flash', $payload);

        $this->assertArrayHasKey('tools', $formatted);
        $this->assertCount(1, $formatted['tools']);
        $this->assertArrayHasKey('functionDeclarations', $formatted['tools'][0]);
        $funcDecl = $formatted['tools'][0]['functionDeclarations'][0];
        $this->assertEquals('get_weather', $funcDecl['name']);
        $this->assertEquals('Get weather for city', $funcDecl['description']);

        $this->assertArrayHasKey('tool_config', $formatted);
        $this->assertEquals(['mode' => 'AUTO'], $formatted['tool_config']['function_calling_config']);
    }

    public function test_formats_tool_calls_and_tool_results_history(): void
    {
        $payload = [
            'messages' => [
                ['role' => 'user', 'content' => 'What is the weather?'],
                [
                    'role' => 'assistant',
                    'content' => '',
                    'tool_calls' => [
                        [
                            'id' => 'call_gemini123',
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
                    'tool_call_id' => 'call_gemini123',
                    'content' => '{"temperature": 22}',
                ],
            ],
        ];

        $formatted = $this->adapter->formatPayload('gemini-2.0-flash', $payload);

        $contents = $formatted['contents'];
        $this->assertCount(3, $contents);

        // Assistant turn mapped to model with functionCall part
        $this->assertEquals('model', $contents[1]['role']);
        $this->assertArrayHasKey('functionCall', $contents[1]['parts'][0]);
        $this->assertEquals('get_weather', $contents[1]['parts'][0]['functionCall']['name']);
        $this->assertEquals(['location' => 'Tokyo'], $contents[1]['parts'][0]['functionCall']['args']);

        // Tool turn mapped to user with functionResponse part
        $this->assertEquals('user', $contents[2]['role']);
        $this->assertArrayHasKey('functionResponse', $contents[2]['parts'][0]);
        $this->assertEquals('get_weather', $contents[2]['parts'][0]['functionResponse']['name']);
        $this->assertEquals(['temperature' => 22], $contents[2]['parts'][0]['functionResponse']['response']['content']);
    }

    public function test_parses_function_call_response_into_openai_tool_calls(): void
    {
        $rawGemini = [
            'candidates' => [
                [
                    'content' => [
                        'role' => 'model',
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'get_weather',
                                    'args' => ['location' => 'Tokyo'],
                                ],
                            ],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
            'usageMetadata' => [
                'promptTokenCount' => 15,
                'candidatesTokenCount' => 10,
                'totalTokenCount' => 25,
            ],
        ];

        $parsed = $this->adapter->parseResponse($rawGemini, 'gemini-2.0-flash');

        $this->assertEquals('tool_calls', $parsed['choices'][0]['finish_reason']);
        $message = $parsed['choices'][0]['message'];
        $this->assertNull($message['content']);
        $this->assertArrayHasKey('tool_calls', $message);
        $this->assertCount(1, $message['tool_calls']);
        $this->assertEquals('get_weather', $message['tool_calls'][0]['function']['name']);
        $this->assertJsonStringEqualsJsonString(
            json_encode(['location' => 'Tokyo']),
            $message['tool_calls'][0]['function']['arguments']
        );
    }

    public function test_transforms_streaming_function_call_chunks(): void
    {
        $streamChunk = 'data: ' . json_encode([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'functionCall' => [
                                    'name' => 'execute_trade',
                                    'args' => ['symbol' => 'NVDA', 'qty' => 10],
                                ],
                            ],
                        ],
                    ],
                    'finishReason' => 'STOP',
                ],
            ],
        ]);

        $translated = $this->adapter->transformStreamChunk($streamChunk, 'gemini-2.0-flash');

        $this->assertNotNull($translated);
        $this->assertStringStartsWith('data: ', $translated);
        $decoded = json_decode(substr(trim($translated), 6), true);

        $this->assertEquals('tool_calls', $decoded['choices'][0]['finish_reason']);
        $toolCall = $decoded['choices'][0]['delta']['tool_calls'][0];
        $this->assertEquals('execute_trade', $toolCall['function']['name']);
        $this->assertJsonStringEqualsJsonString(
            json_encode(['symbol' => 'NVDA', 'qty' => 10]),
            $toolCall['function']['arguments']
        );
    }
}
