<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\NotificationDispatcher;
use App\Domain\Tenancy\AuditRecorder;
use App\Domain\Tenancy\FeatureResolver;
use App\Http\Controllers\Controller;
use App\Models\Agency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConversationController extends Controller
{
    public function __construct(private readonly FeatureResolver $features) {}

    public function messages(Request $request, string $conversation): JsonResponse
    {
        $record = $this->authorized($request, $conversation);
        $this->ensureMessagingEnabled($record);
        $query = DB::table('messages')->where('conversation_id', $record->id)->orderBy('created_at')->orderBy('id');
        if ($after = $request->query('after')) {
            $cursor = DB::table('messages')->where('conversation_id', $record->id)->where('id', $after)->first();
            if ($cursor) {
                $query->where(fn ($builder) => $builder->where('created_at', '>', $cursor->created_at)
                    ->orWhere(fn ($same) => $same->where('created_at', $cursor->created_at)->where('id', '>', $cursor->id)));
            }
        }
        $messages = $query->limit(50)->get();

        return response()->json([
            'data' => $messages->map(fn (object $message) => $this->projection($message))->all(),
            'meta' => ['next_cursor' => $messages->last()?->id],
        ]);
    }

    public function send(
        Request $request,
        string $conversation,
        NotificationDispatcher $notifications,
        AuditRecorder $audit,
    ): JsonResponse {
        $record = $this->authorized($request, $conversation);
        $this->ensureMessagingEnabled($record);
        $validated = $request->validate(['body' => ['required', 'string', 'min:1', 'max:5000']]);
        $messageId = (string) Str::uuid7();
        $now = now();
        DB::transaction(function () use ($request, $record, $validated, $messageId, $now, $notifications, $audit): void {
            DB::table('messages')->insert([
                'id' => $messageId, 'conversation_id' => $record->id,
                'sender_user_id' => $request->user()->id, 'body' => $validated['body'], 'created_at' => $now,
            ]);
            DB::table('conversations')->where('id', $record->id)->update(['last_message_at' => $now, 'updated_at' => $now]);
            $lead = DB::table('leads')->where('id', $record->lead_id)->lockForUpdate()->firstOrFail();
            DB::table('leads')->where('id', $record->lead_id)->update(['last_activity_at' => $now, 'updated_at' => $now]);
            $isAgency = $this->agencyMember($request->user()->id, $record->agency_id);
            if ($isAgency && $lead->first_responded_at === null) {
                $changes = ['first_responded_at' => $now, 'version' => DB::raw('version + 1'), 'updated_at' => $now];
                if ($lead->status === 'new') {
                    $changes['status'] = 'contacted';
                }
                DB::table('leads')->where('id', $record->lead_id)->update($changes);
                if ($lead->status === 'new') {
                    DB::table('lead_status_history')->insert([
                        'id' => (string) Str::uuid(), 'lead_id' => $record->lead_id,
                        'actor_user_id' => $request->user()->id, 'from_status' => 'new',
                        'to_status' => 'contacted', 'created_at' => $now,
                    ]);
                }
                $audit->recordEntity(
                    $request,
                    'lead.first_response_recorded',
                    'lead',
                    $record->lead_id,
                    ['status' => $lead->status, 'first_responded_at' => null],
                    ['status' => $changes['status'] ?? $lead->status, 'first_responded_at' => $now->toISOString()],
                    $record->agency_id,
                );
            }
            $participants = DB::table('conversation_participants')->where('conversation_id', $record->id)
                ->where('user_id', '!=', $request->user()->id)->pluck('user_id');
            foreach ($participants as $participant) {
                $notifications->dispatch($participant, $record->agency_id, 'message.created', 'New message', null, [
                    'conversation_id' => $record->id,
                ], 'message-created:'.$messageId.':'.$participant);
            }
        });

        return response()->json(['data' => $this->projection(DB::table('messages')->where('id', $messageId)->first())], 201);
    }

    private function authorized(Request $request, string $id): object
    {
        $conversation = DB::table('conversations')->where('id', $id)->firstOrFail();
        $participant = DB::table('conversation_participants')->where('conversation_id', $id)
            ->where('user_id', $request->user()->id)->exists();
        if (! $participant && ! $this->agencyMember($request->user()->id, $conversation->agency_id, true)) {
            abort(404);
        }
        if ($request->hasHeader('Agency-ID') && $request->header('Agency-ID') !== $conversation->agency_id && ! $participant) {
            abort(404);
        }

        return $conversation;
    }

    private function ensureMessagingEnabled(object $conversation): void
    {
        $agency = Agency::query()->findOrFail($conversation->agency_id);
        $this->features->ensureEnabled('messaging', $agency);
    }

    private function agencyMember(string $userId, string $agencyId, bool $requirePermission = false): bool
    {
        $query = DB::table('agency_members')->where('agency_members.user_id', $userId)
            ->where('agency_members.agency_id', $agencyId)->where('agency_members.status', 'active');
        if ($requirePermission) {
            $query->join('member_roles', 'member_roles.agency_member_id', '=', 'agency_members.id')
                ->join('role_permissions', 'role_permissions.role_id', '=', 'member_roles.role_id')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('permissions.name', 'lead.manage');
        }

        return $query->exists();
    }

    /** @return array<string, mixed> */
    private function projection(object $message): array
    {
        return ['id' => $message->id, 'sender_user_id' => $message->sender_user_id, 'body' => $message->body, 'created_at' => $message->created_at];
    }
}
