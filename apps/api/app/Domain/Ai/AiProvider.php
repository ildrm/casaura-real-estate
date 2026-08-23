<?php

namespace App\Domain\Ai;

interface AiProvider
{
    /** @param array<int, array<string, mixed>> $context @return array{text: string, title?: string, description?: string, model: string, input_tokens: int, output_tokens: int} */
    public function generate(string $purpose, string $message, array $context): array;

    public function adapter(): string;
}
