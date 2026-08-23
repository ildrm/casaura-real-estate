<?php

namespace App\Console\Commands;

use App\Domain\Search\PublicListingProjector;
use Illuminate\Console\Command;

class RebuildSearchIndex extends Command
{
    protected $signature = 'search:rebuild';

    protected $description = 'Rebuild the public listing search projection from canonical published listings';

    public function handle(PublicListingProjector $projector): int
    {
        $count = $projector->rebuild();
        $this->info("Projected {$count} published listings.");

        return self::SUCCESS;
    }
}
