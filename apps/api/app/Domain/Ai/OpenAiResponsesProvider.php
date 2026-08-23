<?php

namespace App\Domain\Ai;

use App\Domain\ApiException;
use Illuminate\Support\Facades\Http;

final class OpenAiResponsesProvider implements AiProvider
{
    public function generate(string $purpose, string $message, array $context): array
    {
        $apiKey = (string) config('ai.api_key');
        if ($apiKey === '') {
            throw new ApiException('AI_PROVIDER_UNAVAILABLE', 'The AI provider is not configured.', 503);
        }
        $schema = [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
                'title' => ['type' => ['string', 'null']],
                'description' => ['type' => ['string', 'null']],
            ],
            'required' => ['text', 'title', 'description'],
            'additionalProperties' => false,
        ];
        $response = Http::withToken($apiKey)->acceptJson()
            ->timeout((int) config('ai.timeout_seconds', 15))
            ->post(config('ai.base_url').'/v1/responses', [
                'model' => config('ai.model'),
                'store' => false,
                'max_output_tokens' => (int) config('ai.max_output_tokens', 800),
                'instructions' => 'Use only the supplied Casaura context. Never infer missing property facts. Return concise plain text and cite no source outside the supplied context.',
                'input' => [[
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => json_encode([
                            'purpose' => $purpose,
                            'request' => $message,
                            'context' => $context,
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ]],
                'text' => ['format' => [
                    'type' => 'json_schema',
                    'name' => 'casaura_grounded_response',
                    'strict' => true,
                    'schema' => $schema,
                ]],
            ]);
        if (! $response->successful()) {
            throw new ApiException('AI_PROVIDER_UNAVAILABLE', 'The AI provider request failed.', 503);
        }
        $text = $response->json('output.0.content.0.text');
        $decoded = is_string($text) ? json_decode($text, true) : null;
        if (! is_array($decoded) || ! is_string($decoded['text'] ?? null)) {
            throw new ApiException('AI_PROVIDER_RESPONSE_INVALID', 'The AI provider returned invalid structured output.', 502);
        }

        return [
            'text' => $this->plain($decoded['text']),
            'title' => isset($decoded['title']) ? $this->plain((string) $decoded['title']) : null,
            'description' => isset($decoded['description']) ? $this->plain((string) $decoded['description']) : null,
            'model' => (string) ($response->json('model') ?: config('ai.model')),
            'input_tokens' => (int) $response->json('usage.input_tokens', 0),
            'output_tokens' => (int) $response->json('usage.output_tokens', 0),
        ];
    }

    public function adapter(): string
    {
        return 'openai';
    }

    private function plain(string $value): string
    {
        return trim(mb_substr(strip_tags($value), 0, 5000));
    }
}
