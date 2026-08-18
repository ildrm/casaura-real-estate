<?php

namespace App\Domain\Tenancy;

use App\Models\Agency;
use App\Models\AgencyMember;
use LogicException;

final class TenantContext
{
    private ?Agency $agency = null;

    private ?AgencyMember $membership = null;

    public function activate(AgencyMember $membership): void
    {
        if (! $membership->relationLoaded('agency')) {
            $membership->load('agency');
        }

        $this->membership = $membership;
        $this->agency = $membership->agency;
    }

    public function agency(): Agency
    {
        return $this->agency ?? throw new LogicException('No agency tenant is active.');
    }

    public function membership(): AgencyMember
    {
        return $this->membership ?? throw new LogicException('No agency membership is active.');
    }

    public function id(): string
    {
        return $this->agency()->getKey();
    }

    public function clear(): void
    {
        $this->agency = null;
        $this->membership = null;
    }
}
