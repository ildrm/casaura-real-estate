<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Search\PublicListingPresenter;
use App\Domain\Tenancy\FeatureResolver;
use App\Http\Controllers\Controller;
use App\Models\CollectionMember;
use App\Models\CollectionProperty;
use App\Models\ConsumerCollection;
use App\Models\SearchDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CollectionController extends Controller
{
    public function __construct(
        private readonly PublicListingPresenter $presenter,
        private readonly FeatureResolver $features,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $collections = ConsumerCollection::query()
            ->where(fn ($query) => $query
                ->where('owner_user_id', $request->user()->id)
                ->orWhereHas('members', fn ($members) => $members
                    ->where('user_id', $request->user()->id)
                    ->whereNotNull('accepted_at')
                    ->whereNull('revoked_at')))
            ->with(['members', 'items.listing'])
            ->latest('updated_at')->get();

        return response()->json(['data' => $collections->map(
            fn (ConsumerCollection $collection) => $this->data($collection, $request->user()->id),
        )]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $collection = ConsumerCollection::query()->create([
            'owner_user_id' => $request->user()->id,
            'name' => trim($validated['name']),
        ]);

        return response()->json(['data' => $this->data($collection, $request->user()->id)], 201);
    }

    public function show(Request $request, string $collection): JsonResponse
    {
        $record = $this->accessible($request, $collection);

        return response()->json(['data' => $this->data($record, $request->user()->id)]);
    }

    public function update(Request $request, string $collection): JsonResponse
    {
        $record = $this->owned($request, $collection);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        if ((int) $validated['version'] !== $record->version) {
            throw new ApiException('COLLECTION_VERSION_CONFLICT', 'The collection changed.', 409);
        }
        $record->update(['name' => trim($validated['name']), 'version' => $record->version + 1]);

        return response()->json(['data' => $this->data($record, $request->user()->id)]);
    }

    public function destroy(Request $request, string $collection): JsonResponse
    {
        $this->owned($request, $collection)->delete();

        return response()->json(null, 204);
    }

    public function addItem(Request $request, string $collection): JsonResponse
    {
        $record = $this->editable($request, $collection);
        $validated = $request->validate(['listing_id' => ['required', 'uuid']]);
        SearchDocument::query()->where('status', 'published')->findOrFail($validated['listing_id']);
        DB::transaction(function () use ($request, $record, $validated): void {
            $locked = ConsumerCollection::query()->lockForUpdate()->findOrFail($record->id);
            if (! CollectionProperty::query()->where('collection_id', $locked->id)
                ->where('listing_id', $validated['listing_id'])->exists()) {
                CollectionProperty::query()->create([
                    'collection_id' => $locked->id,
                    'listing_id' => $validated['listing_id'],
                    'added_by_user_id' => $request->user()->id,
                    'position' => ((int) CollectionProperty::query()
                        ->where('collection_id', $locked->id)->max('position')) + 1,
                ]);
                $locked->increment('version');
            }
        });

        return response()->json(['data' => $this->data($record->refresh(), $request->user()->id)]);
    }

    public function removeItem(Request $request, string $collection): JsonResponse
    {
        $record = $this->editable($request, $collection);
        $validated = $request->validate(['listing_id' => ['required', 'uuid']]);
        DB::transaction(function () use ($record, $validated): void {
            $deleted = CollectionProperty::query()->where('collection_id', $record->id)
                ->where('listing_id', $validated['listing_id'])->delete();
            if ($deleted) {
                $record->increment('version');
                $this->normalizePositions($record->id);
            }
        });

        return response()->json(['data' => $this->data($record->refresh(), $request->user()->id)]);
    }

    public function reorder(Request $request, string $collection): JsonResponse
    {
        $record = $this->editable($request, $collection);
        $validated = $request->validate([
            'listing_ids' => ['required', 'array', 'max:100'],
            'listing_ids.*' => ['required', 'uuid', 'distinct'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        DB::transaction(function () use ($record, $validated): void {
            $locked = ConsumerCollection::query()->lockForUpdate()->findOrFail($record->id);
            if ($locked->version !== (int) $validated['version']) {
                throw new ApiException('COLLECTION_VERSION_CONFLICT', 'The collection changed.', 409);
            }
            $current = CollectionProperty::query()->where('collection_id', $locked->id)
                ->pluck('listing_id')->sort()->values()->all();
            $submitted = collect($validated['listing_ids'])->sort()->values()->all();
            if ($current !== $submitted) {
                throw new ApiException('COLLECTION_ORDER_INVALID', 'The order must include every current item once.', 422);
            }
            foreach ($validated['listing_ids'] as $index => $listingId) {
                CollectionProperty::query()->where('collection_id', $locked->id)
                    ->where('listing_id', $listingId)->update(['position' => $index + 1001]);
            }
            foreach ($validated['listing_ids'] as $index => $listingId) {
                CollectionProperty::query()->where('collection_id', $locked->id)
                    ->where('listing_id', $listingId)->update(['position' => $index + 1]);
            }
            $locked->increment('version');
        });

        return response()->json(['data' => $this->data($record->refresh(), $request->user()->id)]);
    }

    public function invite(Request $request, string $collection): JsonResponse
    {
        $record = $this->owned($request, $collection);
        $this->features->ensureEnabled('collaborative_collections');
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(['viewer', 'editor'])],
        ]);
        $email = mb_strtolower(trim($validated['email']));
        $token = Str::random(64);
        DB::table('collection_invitations')->insert([
            'id' => (string) Str::uuid(),
            'collection_id' => $record->id,
            'invited_email_hash' => hash('sha256', $email),
            'role' => $validated['role'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => [
            'collection_id' => $record->id,
            'role' => $validated['role'],
            'invitation_token' => $token,
            'expires_at' => now()->addDays(7),
        ]], 201);
    }

    public function revoke(Request $request, string $collection, string $user): JsonResponse
    {
        $record = $this->owned($request, $collection);
        CollectionMember::query()->where('collection_id', $record->id)->where('user_id', $user)
            ->update(['revoked_at' => now()]);

        return response()->json(null, 204);
    }

    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        $this->features->ensureEnabled('collaborative_collections');
        $member = DB::transaction(function () use ($request, $token): CollectionMember {
            $invitation = DB::table('collection_invitations')->where('token_hash', hash('sha256', $token))
                ->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
                ->lockForUpdate()->first();
            abort_unless($invitation, 404);
            if (! hash_equals($invitation->invited_email_hash, hash('sha256', mb_strtolower($request->user()->email)))) {
                abort(404);
            }
            $member = CollectionMember::query()->updateOrCreate([
                'collection_id' => $invitation->collection_id,
                'user_id' => $request->user()->id,
            ], [
                'role' => $invitation->role,
                'accepted_at' => now(),
                'revoked_at' => null,
            ]);
            DB::table('collection_invitations')->where('id', $invitation->id)->update([
                'accepted_at' => now(), 'updated_at' => now(),
            ]);

            return $member;
        });

        return response()->json(['data' => $member]);
    }

    private function accessible(Request $request, string $id): ConsumerCollection
    {
        return ConsumerCollection::query()
            ->where(fn ($query) => $query
                ->where('owner_user_id', $request->user()->id)
                ->orWhereHas('members', fn ($members) => $members
                    ->where('user_id', $request->user()->id)
                    ->whereNotNull('accepted_at')
                    ->whereNull('revoked_at')))
            ->findOrFail($id);
    }

    private function owned(Request $request, string $id): ConsumerCollection
    {
        return ConsumerCollection::query()->where('owner_user_id', $request->user()->id)->findOrFail($id);
    }

    private function editable(Request $request, string $id): ConsumerCollection
    {
        return ConsumerCollection::query()
            ->where(fn ($query) => $query
                ->where('owner_user_id', $request->user()->id)
                ->orWhereHas('members', fn ($members) => $members
                    ->where('user_id', $request->user()->id)
                    ->where('role', 'editor')
                    ->whereNotNull('accepted_at')
                    ->whereNull('revoked_at')))
            ->findOrFail($id);
    }

    private function normalizePositions(string $collectionId): void
    {
        CollectionProperty::query()->where('collection_id', $collectionId)->orderBy('position')
            ->get()->each(fn (CollectionProperty $item, int $index) => $item->update(['position' => $index + 1]));
    }

    /** @return array<string, mixed> */
    private function data(ConsumerCollection $collection, string $userId): array
    {
        $collection->loadMissing(['members', 'items.listing']);
        $role = $collection->owner_user_id === $userId
            ? 'owner'
            : $collection->members->firstWhere('user_id', $userId)?->role;

        return [
            'id' => $collection->id,
            'name' => $collection->name,
            'role' => $role,
            'version' => $collection->version,
            'items' => $collection->items->map(fn (CollectionProperty $item) => [
                'listing_id' => $item->listing_id,
                'position' => $item->position,
                'unavailable' => ! $item->listing || $item->listing->status !== 'published',
                'listing' => $item->listing && $item->listing->status === 'published'
                    ? $this->presenter->card($item->listing)
                    : null,
            ])->values()->all(),
            'members' => $collection->owner_user_id === $userId
                ? $collection->members->whereNull('revoked_at')->map(fn (CollectionMember $member) => [
                    'user_id' => $member->user_id, 'role' => $member->role,
                ])->values()->all()
                : [],
            'created_at' => $collection->created_at,
            'updated_at' => $collection->updated_at,
        ];
    }
}
