<?php

namespace Tests\Feature\Api;

use App\Models\AgencyMember;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesAgencyTenant;
use Tests\TestCase;

class LeadsCollaborationTest extends TestCase
{
    use CreatesAgencyTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('listing_media');
    }

    /** Phase 4 AC-1, AC-2; EC-1, EC-2. */
    public function test_public_inquiry_is_idempotent_and_rejects_unavailable_inventory(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $published = $this->createPublishedListing($owner, $agency, ['reference' => 'LEAD-PUBLISHED']);
        $payload = $this->inquiryPayload();
        $headers = ['Idempotency-Key' => 'inquiry-one'];

        $first = $this->postJson("/api/v1/public/listings/{$published['id']}/leads", $payload, $headers)->assertCreated();
        $second = $this->postJson("/api/v1/public/listings/{$published['id']}/leads", $payload, $headers)->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('lead_status_history', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.created']);
        $this->assertDatabaseHas('analytics_events', ['agency_id' => $agency->id, 'type' => 'lead.created']);

        $this->postJson("/api/v1/public/listings/{$published['id']}/leads", [...$payload, 'message' => 'A materially different inquiry message.'], $headers)
            ->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $draft = $this->postJson('/api/v1/listings', $this->validListingPayload(['reference' => 'LEAD-DRAFT']), $this->agencyHeaders($agency))
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/public/listings/{$draft}/leads", $payload, ['Idempotency-Key' => 'draft-inquiry'])->assertNotFound();
        $this->postJson('/api/v1/public/listings/00000000-0000-0000-0000-000000000000/leads', $payload, ['Idempotency-Key' => 'missing-inquiry'])->assertNotFound();
        $this->postJson("/api/v1/public/listings/{$published['id']}/leads", [...$payload, 'consent' => false], ['Idempotency-Key' => 'invalid-inquiry'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.fields.consent.0', 'The consent field must be accepted.');
        $this->assertDatabaseCount('leads', 1);
    }

    /** Phase 4 AC-3, AC-4; EC-3, EC-4. */
    public function test_lead_pipeline_is_tenant_scoped_versioned_and_audited(): void
    {
        [$ownerA, $agencyA] = $this->createAgencyOwner('Agency A');
        [$ownerB, $agencyB] = $this->createAgencyOwner('Agency B');
        $listingB = $this->createPublishedListing($ownerB, $agencyB, ['reference' => 'LEAD-B']);
        $leadId = $this->postJson("/api/v1/public/listings/{$listingB['id']}/leads", $this->inquiryPayload(), ['Idempotency-Key' => 'lead-b'])
            ->assertCreated()->json('data.id');

        $this->actAsAgencyOwner($ownerA);
        $this->getJson('/api/v1/leads', $this->agencyHeaders($agencyA))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/leads/{$leadId}", $this->agencyHeaders($agencyA))->assertNotFound();
        $this->patchJson("/api/v1/leads/{$leadId}", ['status' => 'contacted', 'version' => 1], $this->agencyHeaders($agencyA))->assertNotFound();

        $this->actAsAgencyOwner($ownerB);
        $member = AgencyMember::query()->where('agency_id', $agencyB->id)->where('user_id', $ownerB->id)->firstOrFail();
        $this->patchJson("/api/v1/leads/{$leadId}", [
            'status' => 'contacted', 'priority' => 'high', 'assigned_member_id' => $member->id, 'version' => 1,
        ], $this->agencyHeaders($agencyB))->assertOk()
            ->assertJsonPath('data.status', 'contacted')->assertJsonPath('data.version', 2);
        $this->assertDatabaseHas('lead_status_history', [
            'lead_id' => $leadId, 'to_status' => 'contacted',
            'from_assigned_member_id' => null, 'to_assigned_member_id' => $member->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['agency_id' => $agencyB->id, 'action' => 'lead.updated']);
        $this->assertDatabaseHas('analytics_events', ['agency_id' => $agencyB->id, 'type' => 'lead.status_changed']);

        $this->patchJson("/api/v1/leads/{$leadId}", ['status' => 'qualified', 'version' => 1], $this->agencyHeaders($agencyB))
            ->assertConflict()->assertJsonPath('error.code', 'LEAD_VERSION_CONFLICT');
        $foreignMember = AgencyMember::query()->where('agency_id', $agencyA->id)->firstOrFail();
        $this->patchJson("/api/v1/leads/{$leadId}", ['assigned_member_id' => $foreignMember->id, 'version' => 2], $this->agencyHeaders($agencyB))
            ->assertUnprocessable()->assertJsonPath('error.code', 'LEAD_ASSIGNEE_INVALID');
    }

    /** P1 workflow AC-2. */
    public function test_lead_transitions_are_explicit_and_clearing_assignment_is_recorded_as_null(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $listing = $this->createPublishedListing($owner, $agency, ['reference' => 'LEAD-TRANSITIONS']);
        $leadId = $this->postJson("/api/v1/public/listings/{$listing['id']}/leads", $this->inquiryPayload(), [
            'Idempotency-Key' => 'lead-transition',
        ])->assertCreated()->json('data.id');
        $ownerMember = AgencyMember::query()->where('agency_id', $agency->id)->where('user_id', $owner->id)->firstOrFail();
        $this->actAsAgencyOwner($owner);

        $this->patchJson("/api/v1/leads/{$leadId}", ['status' => 'won', 'version' => 1], $this->agencyHeaders($agency))
            ->assertConflict()->assertJsonPath('error.code', 'LEAD_TRANSITION_INVALID');
        $this->assertDatabaseHas('leads', ['id' => $leadId, 'status' => 'new', 'version' => 1]);

        $this->patchJson("/api/v1/leads/{$leadId}", [
            'status' => 'contacted', 'assigned_member_id' => $ownerMember->id, 'version' => 1,
        ], $this->agencyHeaders($agency))->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson("/api/v1/leads/{$leadId}", [
            'assigned_member_id' => null, 'version' => 2,
        ], $this->agencyHeaders($agency))->assertOk()->assertJsonPath('data.assigned_member_id', null);

        $history = DB::table('lead_status_history')->where('lead_id', $leadId)->latest('created_at')->firstOrFail();
        $this->assertSame($ownerMember->id, $history->from_assigned_member_id);
        $this->assertNull($history->to_assigned_member_id);
    }

    /** Phase 4 AC-5; EC-5, EC-6. */
    public function test_messages_are_cursor_pollable_and_participant_scoped(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $listing = $this->createPublishedListing($owner, $agency, ['reference' => 'MESSAGE-LISTING']);
        $consumer = User::factory()->create();
        Sanctum::actingAs($consumer);
        $lead = $this->postJson("/api/v1/public/listings/{$listing['id']}/leads", $this->inquiryPayload(), ['Idempotency-Key' => 'message-lead'])
            ->assertCreated()->json('data');
        $conversationId = $lead['conversation_id'];

        $initial = $this->getJson("/api/v1/conversations/{$conversationId}/messages")->assertOk();
        $cursor = $initial->json('meta.next_cursor');
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'Could we arrange a Saturday visit?'])
            ->assertCreated()->assertJsonPath('data.body', 'Could we arrange a Saturday visit?');
        $this->getJson("/api/v1/conversations/{$conversationId}/messages?after={$cursor}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => ''])->assertUnprocessable();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/conversations/{$conversationId}/messages")->assertNotFound();

        $this->actAsAgencyOwner($owner);
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => 'Saturday at 10:00 works.'], $this->agencyHeaders($agency))
            ->assertCreated();
        $this->assertDatabaseHas('lead_status_history', [
            'lead_id' => $lead['id'], 'from_status' => 'new', 'to_status' => 'contacted',
        ]);
        $this->assertDatabaseHas('audit_logs', ['agency_id' => $agency->id, 'action' => 'lead.first_response_recorded']);
    }

    /** Phase 4 AC-6, AC-7, AC-8; EC-7, EC-8. */
    public function test_viewings_validate_transition_and_export_an_authorized_calendar_event(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $listing = $this->createPublishedListing($owner, $agency, ['reference' => 'VIEWING-LISTING']);
        $consumer = User::factory()->create();
        Sanctum::actingAs($consumer);
        $leadId = $this->postJson("/api/v1/public/listings/{$listing['id']}/leads", $this->inquiryPayload(), ['Idempotency-Key' => 'viewing-lead'])
            ->assertCreated()->json('data.id');
        $this->actAsAgencyOwner($owner);
        $startsAt = now()->addDays(2)->setTime(10, 0);

        $this->postJson('/api/v1/viewings', [
            'lead_id' => $leadId, 'starts_at' => $startsAt->toISOString(), 'ends_at' => $startsAt->copy()->subHour()->toISOString(), 'timezone' => 'UTC',
        ], $this->agencyHeaders($agency))->assertUnprocessable();
        $this->assertDatabaseCount('viewing_requests', 0);

        $viewing = $this->postJson('/api/v1/viewings', [
            'lead_id' => $leadId, 'starts_at' => $startsAt->toISOString(), 'ends_at' => $startsAt->copy()->addHour()->toISOString(), 'timezone' => 'UTC',
        ], $this->agencyHeaders($agency))->assertCreated()->assertJsonPath('data.warnings', [])->json('data');
        $this->postJson('/api/v1/viewings', [
            'lead_id' => $leadId, 'starts_at' => $startsAt->copy()->addMinutes(30)->toISOString(),
            'ends_at' => $startsAt->copy()->addMinutes(90)->toISOString(), 'timezone' => 'UTC',
        ], $this->agencyHeaders($agency))->assertCreated()
            ->assertJsonPath('data.warnings.0.code', 'VIEWING_SCHEDULE_OVERLAP')
            ->assertJsonPath('data.warnings.0.overlap_count', 1);
        $firstPage = $this->getJson('/api/v1/viewings?limit=1', $this->agencyHeaders($agency))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotNull($firstPage->json('meta.next_cursor'));
        $this->getJson('/api/v1/viewings?limit=1&cursor='.urlencode($firstPage->json('meta.next_cursor')), $this->agencyHeaders($agency))
            ->assertOk()->assertJsonCount(1, 'data');
        $this->get("/api/v1/viewings/{$viewing['id']}/calendar", $this->agencyHeaders($agency))
            ->assertConflict()->assertJsonPath('error.code', 'VIEWING_NOT_EXPORTABLE');
        $this->patchJson("/api/v1/viewings/{$viewing['id']}", ['status' => 'confirmed', 'version' => 1], $this->agencyHeaders($agency))
            ->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->get("/api/v1/viewings/{$viewing['id']}/calendar", $this->agencyHeaders($agency))
            ->assertOk()->assertHeader('content-type', 'text/calendar; charset=UTF-8')->assertSee('BEGIN:VEVENT');
        $this->assertDatabaseHas('leads', ['id' => $leadId, 'status' => 'viewing']);
        $this->assertDatabaseHas('viewing_status_history', ['viewing_request_id' => $viewing['id'], 'to_status' => 'confirmed']);
        $this->assertDatabaseHas('analytics_events', ['agency_id' => $agency->id, 'type' => 'viewing.confirmed']);
        $this->assertDatabaseHas('analytics_events', ['agency_id' => $agency->id, 'type' => 'lead.status_changed']);
        $this->assertDatabaseHas('audit_logs', ['agency_id' => $agency->id, 'action' => 'lead.status_changed_by_viewing']);

        Sanctum::actingAs($consumer);
        $this->getJson('/api/v1/account/collaboration')->assertOk()->assertJsonPath('data.viewings.0.id', $viewing['id']);

        $analyst = User::factory()->create();
        $analystMember = AgencyMember::query()->create([
            'agency_id' => $agency->id, 'user_id' => $analyst->id, 'status' => 'active', 'accepted_at' => now(),
        ]);
        $analystMember->roles()->attach(Role::query()->where('slug', 'agency_analyst')->firstOrFail());
        Sanctum::actingAs($analyst);
        $this->get("/api/v1/viewings/{$viewing['id']}/calendar", $this->agencyHeaders($agency))->assertNotFound();
    }

    /** Phase 4 AC-9; EC-9. */
    public function test_due_reminders_and_notifications_are_idempotent_and_private(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        [$foreignOwner, $foreignAgency] = $this->createAgencyOwner('Foreign reminder agency');
        $foreignListing = $this->createPublishedListing($foreignOwner, $foreignAgency, ['reference' => 'FOREIGN-REMINDER']);
        $foreignLead = $this->postJson("/api/v1/public/listings/{$foreignListing['id']}/leads", $this->inquiryPayload(), [
            'Idempotency-Key' => 'foreign-reminder-lead',
        ])->assertCreated()->json('data.id');
        $this->actAsAgencyOwner($owner);
        $this->postJson('/api/v1/reminders', [
            'title' => 'Do not link a foreign lead', 'due_at' => now()->addHour()->toISOString(), 'lead_id' => $foreignLead,
        ], $this->agencyHeaders($agency))->assertNotFound();
        $reminder = $this->postJson('/api/v1/reminders', [
            'title' => 'Follow up with the buyer', 'due_at' => now()->subMinute()->toISOString(),
        ], $this->agencyHeaders($agency))->assertCreated()->json('data');

        $this->artisan('reminders:dispatch')->assertSuccessful();
        $this->artisan('reminders:dispatch')->assertSuccessful();
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data');
        $notificationId = $this->getJson('/api/v1/notifications')->json('data.0.id');
        $this->patchJson("/api/v1/notifications/{$notificationId}", ['read' => true])->assertOk()->assertJsonPath('data.read', true);
        $this->patchJson("/api/v1/reminders/{$reminder['id']}", ['status' => 'completed'], $this->agencyHeaders($agency))
            ->assertOk()->assertJsonPath('data.status', 'completed');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/notifications/{$notificationId}", ['read' => true])->assertNotFound();
    }

    /** P1 workflow AC-4. */
    public function test_due_reminder_dispatch_is_registered_as_a_singleton_minute_schedule(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());
        $event = $events->first(fn ($candidate) => str_contains($candidate->command ?? '', 'reminders:dispatch'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    /** Phase 4 AC-10. */
    public function test_response_analytics_are_derived_from_lead_timestamps(): void
    {
        [$owner, $agency] = $this->createAgencyOwner();
        $listing = $this->createPublishedListing($owner, $agency, ['reference' => 'ANALYTICS-LISTING']);
        foreach (['analytics-one', 'analytics-two'] as $key) {
            $this->postJson("/api/v1/public/listings/{$listing['id']}/leads", $this->inquiryPayload(['email' => "{$key}@example.com"]), ['Idempotency-Key' => $key])->assertCreated();
        }
        $lead = $this->postJson("/api/v1/public/listings/{$listing['id']}/leads", $this->inquiryPayload(['email' => 'responded@example.com']), ['Idempotency-Key' => 'analytics-three'])
            ->assertCreated()->json('data');
        $this->actAsAgencyOwner($owner);
        $this->patchJson("/api/v1/leads/{$lead['id']}", ['status' => 'contacted', 'version' => 1], $this->agencyHeaders($agency))->assertOk();

        $this->getJson('/api/v1/agency/analytics/collaboration', $this->agencyHeaders($agency))->assertOk()
            ->assertJsonPath('data.total_leads', 3)->assertJsonPath('data.responded_leads', 1)
            ->assertJsonPath('data.response_rate', 33.33);
    }

    /** @return array<string, mixed> */
    private function inquiryPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Jordan Rivera', 'email' => 'jordan@example.com', 'phone' => '+1 512 555 0199',
            'message' => 'I would like more information about this property and its availability.', 'consent' => true,
        ], $overrides);
    }
}
