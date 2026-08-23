<?php

namespace App\Notifications;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AgencyInvitation extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $token,
        public readonly Agency $agency,
        public readonly User $inviter,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('identity.frontend_url'), '/')
            .'/invitations/accept?token='.urlencode($this->token);

        return (new MailMessage)
            ->subject("You're invited to join {$this->agency->name} on Casaura")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->inviter->name} invited you to join {$this->agency->name}.")
            ->action('Accept invitation', $url)
            ->line('This invitation expires in '.config('identity.invitations.ttl_hours').' hours.')
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }
}
