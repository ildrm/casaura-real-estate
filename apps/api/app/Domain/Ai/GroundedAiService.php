<?php

namespace App\Domain\Ai;

use App\Domain\ApiException;
use App\Models\AiGeneration;
use App\Models\AiSession;
use App\Models\SearchDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class GroundedAiService
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly DeterministicAiProvider $fallback,
    ) {}

    /** @return array<string, mixed> */
    public function search(string $message, ?User $user): array
    {
        $this->assertSafe($message, $user);
        $redacted = $this->redact($message);
        $filters = $this->parseFilters($redacted);
        $query = SearchDocument::query()->where('status', 'published');
        if (isset($filters['bedrooms_min'])) {
            $query->where('bedrooms', '>=', $filters['bedrooms_min']);
        }
        if (isset($filters['price_max_minor'])) {
            $query->where('price_amount_minor', '<=', $filters['price_max_minor']);
        }
        if (isset($filters['property_type_slug'])) {
            $query->where('property_type_slug', $filters['property_type_slug']);
        }
        if (isset($filters['locality'])) {
            $query->whereRaw('lower(locality) = ?', [mb_strtolower($filters['locality'])]);
        }
        $documents = $query->latest('listed_at')->limit(12)->get();
        $context = $documents->map(fn (SearchDocument $document) => $this->publicFacts($document))->all();
        [$result, $adapter, $latency] = $this->generateWithFallback('search', $redacted, $context, true);
        $generation = $this->persistGeneration($user, null, null, 'search', $redacted, $filters, $result, $adapter, $latency);
        $citations = $documents->map(fn (SearchDocument $document) => $this->citation(
            $generation, $document, ['title', 'price_amount_minor', 'bedrooms', 'bathrooms', 'locality'],
        ))->all();

        return [
            'id' => $generation->id,
            'message' => $result['text'],
            'parsed_filters' => $filters,
            'filters_applied' => false,
            'assumptions' => ['Currency is USD unless the request states otherwise.', 'Only current published Casaura records are cited.'],
            'citations' => $citations,
            'matches' => $context,
            'safety' => ['status' => 'allowed'],
            'provider' => ['adapter' => $adapter, 'model' => $result['model']],
        ];
    }

    /** @param list<string> $listingIds @return array<string, mixed> */
    public function comparison(string $message, array $listingIds, ?User $user): array
    {
        $this->assertSafe($message, $user);
        $ids = array_values(array_unique($listingIds));
        if (count($ids) < 2 || count($ids) > 5) {
            throw new ApiException('COMPARISON_SIZE_INVALID', 'Choose between two and five listings.', 422);
        }
        $documents = SearchDocument::query()->where('status', 'published')->whereIn('listing_id', $ids)
            ->get()->keyBy('listing_id');
        if ($documents->count() !== count($ids)) {
            abort(404);
        }
        $ordered = collect($ids)->map(fn (string $id) => $documents->get($id));
        $context = $ordered->map(fn (SearchDocument $document) => $this->publicFacts($document))->all();
        [$result, $adapter, $latency] = $this->generateWithFallback('comparison', $this->redact($message), $context, true);
        $generation = $this->persistGeneration($user, null, null, 'comparison', $message, null, $result, $adapter, $latency);
        $citations = $ordered->map(fn (SearchDocument $document) => $this->citation(
            $generation, $document, ['title', 'price_amount_minor', 'bedrooms', 'bathrooms', 'interior_area_sqm', 'amenities'],
        ))->all();

        return [
            'id' => $generation->id,
            'message' => $result['text'],
            'citations' => $citations,
            'facts' => $context,
            'provider' => ['adapter' => $adapter, 'model' => $result['model']],
            'safety' => ['status' => 'allowed'],
        ];
    }

    /** @param array<string, mixed> $facts @return array{result: array<string, mixed>, generation: AiGeneration} */
    public function listing(string $instruction, array $facts, string $agencyId, string $listingId, User $user): array
    {
        $this->assertSafe($instruction, $user);
        [$result, $adapter, $latency] = $this->generateWithFallback('listing', $this->redact($instruction), [$facts], false);
        $generation = $this->persistGeneration(
            $user,
            $agencyId,
            $listingId,
            'listing',
            $instruction,
            null,
            $result,
            $adapter,
            $latency,
        );

        return ['result' => $result, 'generation' => $generation];
    }

    private function assertSafe(string $message, ?User $user): void
    {
        $patterns = [
            'prompt_injection' => '/ignore (all |the )?(previous|prior) instructions|system prompt|hidden instructions/i',
            'private_data' => '/private (owner|member|user).*(email|phone|address)|reveal.*(email|credential|secret)/i',
            'discriminatory_steering' => '/\b(race|religion|ethnicity|nationality)\b.*\b(neighborhood|area|buyer|tenant)\b/i',
            'financial_certainty' => '/guaranteed (return|investment|profit)|certain to appreciate/i',
            'professional_advice' => '/\b(legal|tax|mortgage|investment|appraisal) (advice|recommendation)|\bshould i (sue|evade tax|hide income)\b/i',
            'fair_housing' => '/\b(best|safe|good) (neighborhood|area) for (a |my )?(race|religion|ethnicity|nationality|family type)\b/i',
        ];
        foreach ($patterns as $category => $pattern) {
            if (preg_match($pattern, $message) === 1) {
                $generationId = (string) Str::uuid();
                DB::table('ai_generations')->insert([
                    'id' => $generationId,
                    'ai_session_id' => null,
                    'agency_id' => null,
                    'listing_id' => null,
                    'adapter' => 'none',
                    'model' => 'safety-rules-v1',
                    'purpose' => 'safety',
                    'status' => 'refused',
                    'prompt_hash' => hash('sha256', $this->redact($message)),
                    'latency_ms' => 0,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'safety_code' => mb_strtoupper($category),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('ai_safety_events')->insert([
                    'id' => (string) Str::uuid(),
                    'ai_generation_id' => $generationId,
                    'category' => $category,
                    'action' => 'refused',
                    'rule_version' => '1',
                    'created_at' => now(),
                ]);
                throw new ApiException('AI_SAFETY_REFUSAL', 'The assistant cannot process that request.', 422);
            }
        }
    }

    /** @param array<int, array<string, mixed>> $context @return array{array<string, mixed>, string, int} */
    private function generateWithFallback(string $purpose, string $message, array $context, bool $allowFallback): array
    {
        $started = hrtime(true);
        try {
            $result = $this->provider->generate($purpose, $message, $context);
            $adapter = $this->provider->adapter();
        } catch (Throwable $firstException) {
            if ($this->provider->adapter() === 'deterministic') {
                throw $firstException;
            }
            try {
                $result = $this->provider->generate($purpose, $message, $context);
                $adapter = $this->provider->adapter();
            } catch (Throwable $secondException) {
                if (! $allowFallback) {
                    throw $secondException;
                }
                $result = $this->fallback->generate($purpose, $message, $context);
                $adapter = 'deterministic_fallback';
            }
        }
        $result['text'] = trim(mb_substr(strip_tags((string) $result['text']), 0, 5000));

        return [$result, $adapter, (int) round((hrtime(true) - $started) / 1_000_000)];
    }

    /** @param array<string, mixed>|null $filters @param array<string, mixed> $result */
    private function persistGeneration(
        ?User $user,
        ?string $agencyId,
        ?string $listingId,
        string $purpose,
        string $message,
        ?array $filters,
        array $result,
        string $adapter,
        int $latency,
    ): AiGeneration {
        $session = AiSession::query()->create([
            'user_id' => $user?->id,
            'purpose' => $purpose,
            'content_expires_at' => now()->addDays((int) config('ai.retention_days', 30)),
        ]);
        DB::table('ai_messages')->insert([
            'id' => (string) Str::uuid(),
            'ai_session_id' => $session->id,
            'role' => 'user',
            'content' => $this->redact($message),
            'content_hash' => hash('sha256', $this->redact($message)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $generation = AiGeneration::query()->create([
            'ai_session_id' => $session->id,
            'agency_id' => $agencyId,
            'listing_id' => $listingId,
            'adapter' => $adapter,
            'model' => (string) $result['model'],
            'purpose' => $purpose,
            'status' => 'completed',
            'prompt_hash' => hash('sha256', $this->redact($message)),
            'parsed_filters' => $filters,
            'output' => [
                'text' => $result['text'],
                'title' => $result['title'] ?? null,
                'description' => $result['description'] ?? null,
            ],
            'latency_ms' => $latency,
            'input_tokens' => (int) ($result['input_tokens'] ?? 0),
            'output_tokens' => (int) ($result['output_tokens'] ?? 0),
            'content_expires_at' => now()->addDays((int) config('ai.retention_days', 30)),
        ]);
        Log::info('ai.generation_completed', [
            'generation_id' => $generation->id,
            'adapter' => $adapter,
            'model' => $generation->model,
            'purpose' => $purpose,
            'latency_ms' => $latency,
            'input_tokens' => $generation->input_tokens,
            'output_tokens' => $generation->output_tokens,
        ]);

        return $generation;
    }

    /** @param list<string> $fields @return array<string, mixed> */
    private function citation(AiGeneration $generation, SearchDocument $document, array $fields): array
    {
        DB::table('ai_citations')->insert([
            'id' => (string) Str::uuid(),
            'ai_generation_id' => $generation->id,
            'source_type' => 'listing',
            'source_id' => $document->listing_id,
            'field_paths' => json_encode($fields, JSON_THROW_ON_ERROR),
            'snapshot_hash' => hash('sha256', json_encode($this->publicFacts($document), JSON_THROW_ON_ERROR)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'listing_id' => $document->listing_id,
            'url' => "/property/{$document->slug}-{$document->listing_id}",
            'fields' => $fields,
            'projection_version' => $document->projection_version,
        ];
    }

    /** @return array<string, mixed> */
    private function publicFacts(SearchDocument $document): array
    {
        return [
            'listing_id' => $document->listing_id,
            'title' => $document->title,
            'price_amount_minor' => $document->price_amount_minor,
            'currency' => $document->price_currency,
            'property_type' => $document->property_type_name,
            'property_type_slug' => $document->property_type_slug,
            'bedrooms' => $document->bedrooms,
            'bathrooms' => $document->bathrooms,
            'interior_area_sqm' => $document->interior_area_sqm,
            'locality' => $document->locality,
            'region' => $document->region,
            'amenities' => $document->amenities,
            'features' => $document->features,
            'listed_at' => $document->listed_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function parseFilters(string $message): array
    {
        $filters = [];
        if (preg_match('/(\d+)\s*(?:bed|bedroom)/i', $message, $match)) {
            $filters['bedrooms_min'] = min(20, (int) $match[1]);
        }
        if (preg_match('/(?:under|below|max(?:imum)?)\s*\$?\s*([\d,.]+)\s*([mk])?/i', $message, $match)) {
            $amount = (float) str_replace(',', '', $match[1]);
            $multiplier = match (mb_strtolower($match[2] ?? '')) {
                'm' => 1_000_000,
                'k' => 1_000,
                default => 1,
            };
            $filters['price_max_minor'] = (int) round($amount * $multiplier * 100);
            $filters['currency'] = 'USD';
        }
        foreach (['house', 'apartment', 'townhouse', 'land', 'commercial'] as $type) {
            if (preg_match('/\b'.preg_quote($type, '/').'\b/i', $message)) {
                $filters['property_type_slug'] = $type;
                break;
            }
        }
        $localities = SearchDocument::query()->whereNotNull('locality')->distinct()->pluck('locality');
        foreach ($localities as $locality) {
            if (preg_match('/\b'.preg_quote((string) $locality, '/').'\b/i', $message)) {
                $filters['locality'] = $locality;
                break;
            }
        }

        return $filters;
    }

    private function redact(string $message): string
    {
        $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $message) ?? $message;
        $message = preg_replace(
            '/\b\d{1,6}\s+(?:[\pL0-9.\'\x{2019}-]+\s+){0,5}(?:street|st|avenue|ave|road|rd|boulevard|blvd|lane|ln|drive|dr|court|ct|way)\b\.?/iu',
            '[redacted-address]',
            $message,
        ) ?? $message;

        return preg_replace('/(?<!\d)(?:\+?\d[\d .()-]{7,}\d)(?!\d)/', '[redacted-phone]', $message) ?? $message;
    }
}
