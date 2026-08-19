<?php

namespace App\Console\Commands;

use App\Domain\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchDueReminders extends Command
{
    protected $signature = 'reminders:dispatch';

    protected $description = 'Dispatch due collaboration reminders to the in-app notification adapter';

    public function handle(NotificationDispatcher $notifications): int
    {
        DB::transaction(function () use ($notifications): void {
            $due = DB::table('reminders')->where('status', 'pending')->whereNull('dispatched_at')->where('due_at', '<=', now())->lockForUpdate()->get();
            foreach ($due as $reminder) {
                $notifications->dispatch($reminder->assigned_user_id, $reminder->agency_id, 'reminder.due', $reminder->title, null, [
                    'reminder_id' => $reminder->id,
                ], 'reminder-due:'.$reminder->id);
                DB::table('reminders')->where('id', $reminder->id)->update(['dispatched_at' => now(), 'updated_at' => now()]);
            }
        });

        return self::SUCCESS;
    }
}
