<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Search\PublicListingPresenter;
use App\Domain\Tenancy\FeatureResolver;
use App\Http\Controllers\Controller;
use App\Models\SearchDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ComparisonController extends Controller
{
    public function __construct(
        private readonly FeatureResolver $features,
        private readonly PublicListingPresenter $presenter,
    ) {}

    public function compare(Request $request): JsonResponse
    {
        $this->features->ensureEnabled('comparisons');
        $ids = $this->ids(explode(',', (string) $request->query('ids', '')));

        return response()->json(['data' => [
            'items' => $this->documents($ids)->map(fn (SearchDocument $document) => $this->comparison($document))->all(),
            'generated_at' => now()->toISOString(),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('comparison_histories')
            ->where('user_id', $request->user()->id)->latest()->limit(50)->get()
            ->map(fn ($history) => [
                'id' => $history->id,
                'listing_ids' => json_decode((string) $history->listing_ids, true) ?: [],
                'created_at' => $history->created_at,
            ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->features->ensureEnabled('comparisons');
        $validated = $request->validate([
            'listing_ids' => ['required', 'array', 'min:2', 'max:5'],
            'listing_ids.*' => ['required', 'uuid'],
        ]);
        $ids = $this->ids($validated['listing_ids']);
        $this->documents($ids);
        $fingerprint = hash('sha256', implode('|', $ids));
        $existing = DB::table('comparison_histories')->where('user_id', $request->user()->id)
            ->where('fingerprint', $fingerprint)->first();
        if ($existing) {
            return response()->json(['data' => $this->history($existing)]);
        }
        $id = (string) Str::uuid();
        DB::table('comparison_histories')->insert([
            'id' => $id,
            'user_id' => $request->user()->id,
            'listing_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
            'fingerprint' => $fingerprint,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => $this->history(DB::table('comparison_histories')->find($id))], 201);
    }

    public function destroy(Request $request, string $comparison): JsonResponse
    {
        $deleted = DB::table('comparison_histories')->where('user_id', $request->user()->id)
            ->where('id', $comparison)->delete();
        abort_unless($deleted, 404);

        return response()->json(null, 204);
    }

    /** @param list<string> $input @return list<string> */
    private function ids(array $input): array
    {
        $ids = array_values(array_unique(array_filter(array_map('trim', $input))));
        if (count($ids) < 2 || count($ids) > 5) {
            throw new ApiException('COMPARISON_SIZE_INVALID', 'Choose between two and five unique listings.', 422);
        }
        foreach ($ids as $id) {
            if (! Str::isUuid($id)) {
                throw new ApiException('COMPARISON_LISTING_INVALID', 'A comparison listing ID is invalid.', 422);
            }
        }

        return $ids;
    }

    /** @param list<string> $ids @return \Illuminate\Support\Collection<int, SearchDocument> */
    private function documents(array $ids)
    {
        $documents = SearchDocument::query()->where('status', 'published')->whereIn('listing_id', $ids)
            ->get()->keyBy('listing_id');
        if ($documents->count() !== count($ids)) {
            abort(404);
        }

        return collect($ids)->map(fn (string $id) => $documents->get($id));
    }

    /** @return array<string, mixed> */
    private function comparison(SearchDocument $document): array
    {
        return [
            ...$this->presenter->card($document),
            'description' => $document->description,
            'amenities' => $document->amenities,
            'features' => $document->features,
            'freshness' => [
                'listed_at' => $document->listed_at,
                'projected_at' => $document->updated_at,
                'projection_version' => $document->projection_version,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function history(object $history): array
    {
        return [
            'id' => $history->id,
            'listing_ids' => json_decode((string) $history->listing_ids, true) ?: [],
            'created_at' => $history->created_at,
        ];
    }
}
