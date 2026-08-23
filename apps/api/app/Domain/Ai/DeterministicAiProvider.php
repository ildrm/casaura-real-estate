<?php

namespace App\Domain\Ai;

final class DeterministicAiProvider implements AiProvider
{
    public function generate(string $purpose, string $message, array $context): array
    {
        $result = match ($purpose) {
            'listing' => $this->listing($context[0] ?? []),
            'comparison' => [
                'text' => 'The comparison below uses only the current cited listing facts. Missing facts are not inferred.',
            ],
            default => [
                'text' => count($context).' current listing'.(count($context) === 1 ? '' : 's').
                    ' match the parsed criteria. Review the filters and cited records before applying them.',
            ],
        };

        return [
            ...$result,
            'model' => 'casaura-grounded-v1',
            'input_tokens' => 0,
            'output_tokens' => 0,
        ];
    }

    public function adapter(): string
    {
        return 'deterministic';
    }

    /** @param array<string, mixed> $facts @return array{text: string, title: string, description: string} */
    private function listing(array $facts): array
    {
        $type = (string) ($facts['property_type'] ?? 'Property');
        $locality = (string) ($facts['locality'] ?? 'the local area');
        $bedrooms = isset($facts['bedrooms']) ? (int) $facts['bedrooms'] : null;
        $title = trim(($bedrooms ? "{$bedrooms}-bedroom " : '').mb_strtolower($type)." in {$locality}");
        $details = array_filter([
            $bedrooms ? "{$bedrooms} bedrooms" : null,
            isset($facts['bathrooms']) ? rtrim(rtrim((string) $facts['bathrooms'], '0'), '.').' bathrooms' : null,
            isset($facts['interior_area_sqm']) ? round((float) $facts['interior_area_sqm']).' square metres' : null,
        ]);
        $description = ucfirst($type).' in '.$locality.
            ($details ? ' with '.implode(', ', $details) : '').
            '. This draft uses only the verified listing facts and requires human review before publication.';

        return ['text' => $description, 'title' => ucfirst($title), 'description' => $description];
    }
}
