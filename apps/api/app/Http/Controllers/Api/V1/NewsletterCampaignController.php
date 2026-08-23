<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ApiException;
use App\Domain\Newsletters\LocalNewsletterDelivery;
use App\Domain\Newsletters\NewsletterDelivery;
use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\FeatureResolver;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NewsletterCampaignController extends Controller
{
    public function index(TenantContext $tenant, FeatureResolver $features): JsonResponse
    {
        $this->ensureEnabled($tenant, $features);
        $items = DB::table('newsletter_campaigns')->where('agency_id', $tenant->id())->orderByDesc('created_at')->limit(50)->get();

        return response()->json(['data' => $items->map(fn (object $item) => $this->projection($item))]);
    }

    public function store(Request $request, TenantContext $tenant, FeatureResolver $features, AuditRecorder $audit): JsonResponse
    {
        $this->ensureEnabled($tenant, $features);
        $validated = $request->validate([
            'subject' => ['required', 'string', 'min:2', 'max:200'], 'body' => ['required', 'string', 'min:2', 'max:50000'],
        ]);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($request, $tenant, $audit, $validated, $id): void {
            DB::table('newsletter_campaigns')->insert([
                'id' => $id, 'agency_id' => $tenant->id(), 'author_user_id' => $request->user()->id,
                'subject' => $validated['subject'], 'body' => $validated['body'], 'status' => 'draft',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $audit->recordEntity($request, 'newsletter.campaign_created', 'newsletter_campaign', $id, null, ['status' => 'draft'], $tenant->id());
        });

        return response()->json(['data' => $this->projection($this->find($id, $tenant))], 201);
    }

    public function update(Request $request, string $campaign, TenantContext $tenant, FeatureResolver $features, AuditRecorder $audit): JsonResponse
    {
        $this->ensureEnabled($tenant, $features);
        $validated = $request->validate([
            'subject' => ['sometimes', 'string', 'min:2', 'max:200'], 'body' => ['sometimes', 'string', 'min:2', 'max:50000'],
        ]);
        $current = $this->find($campaign, $tenant);
        if ($current->status !== 'draft') {
            throw new ApiException('CAMPAIGN_IMMUTABLE', 'A sent campaign cannot be changed.', 409);
        }
        DB::transaction(function () use ($request, $tenant, $audit, $validated, $campaign): void {
            DB::table('newsletter_campaigns')->where('id', $campaign)->update(array_merge($validated, ['updated_at' => now()]));
            $audit->recordEntity($request, 'newsletter.campaign_updated', 'newsletter_campaign', $campaign, null, array_keys($validated), $tenant->id());
        });

        return response()->json(['data' => $this->projection($this->find($campaign, $tenant))]);
    }

    public function send(Request $request, string $campaign, TenantContext $tenant, FeatureResolver $features, NewsletterDelivery $delivery, AuditRecorder $audit): JsonResponse
    {
        $this->ensureEnabled($tenant, $features);
        if (app()->environment('production') && $delivery instanceof LocalNewsletterDelivery) {
            throw new ApiException('DELIVERY_UNAVAILABLE', 'Newsletter delivery is not configured.', 503);
        }
        $this->find($campaign, $tenant);
        DB::transaction(function () use ($request, $campaign, $tenant, $delivery, $audit): void {
            $current = DB::table('newsletter_campaigns')->where('agency_id', $tenant->id())
                ->where('id', $campaign)->lockForUpdate()->firstOrFail();
            if ($current->status === 'sent') {
                return;
            }
            $subscribers = DB::table('newsletter_subscribers')->where('agency_id', $tenant->id())->where('status', 'active')->get();
            foreach ($subscribers as $subscriber) {
                $success = $delivery->send($subscriber->email, (array) $current);
                DB::table('newsletter_events')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'campaign_id' => $campaign, 'subscriber_id' => $subscriber->id,
                    'event_type' => $success ? 'delivered' : 'failed', 'adapter' => $delivery->name(),
                    'error_code' => $success ? null : 'DELIVERY_FAILED', 'created_at' => now(),
                ]);
            }
            DB::table('newsletter_campaigns')->where('id', $campaign)->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
            $audit->recordEntity($request, 'newsletter.campaign_sent', 'newsletter_campaign', $campaign, ['status' => 'draft'], ['status' => 'sent'], $tenant->id());
        });

        return response()->json(['data' => $this->projection($this->find($campaign, $tenant))]);
    }

    private function ensureEnabled(TenantContext $tenant, FeatureResolver $features): void
    {
        if (! $features->resolve('newsletters', $tenant->agency())['enabled']) {
            throw new ApiException('FEATURE_DISABLED', 'Newsletters are not enabled for this agency.', 403);
        }
    }

    private function find(string $id, TenantContext $tenant): object
    {
        return DB::table('newsletter_campaigns')->where('agency_id', $tenant->id())->where('id', $id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function projection(object $campaign): array
    {
        return [
            'id' => $campaign->id, 'subject' => $campaign->subject, 'body' => $campaign->body,
            'status' => $campaign->status, 'sent_at' => $campaign->sent_at,
            'delivery_count' => DB::table('newsletter_events')->where('campaign_id', $campaign->id)->count(),
        ];
    }
}
