<?php

namespace App\Domain\Newsletters;

final class LocalNewsletterDelivery implements NewsletterDelivery
{
    public function send(string $email, array $campaign): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'local';
    }
}
