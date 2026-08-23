<?php

namespace App\Domain\Newsletters;

interface NewsletterDelivery
{
    /** @param array<string, mixed> $campaign */
    public function send(string $email, array $campaign): bool;

    public function name(): string;
}
