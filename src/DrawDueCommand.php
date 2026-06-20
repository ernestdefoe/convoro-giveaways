<?php

namespace Convoro\Ext\Giveaways;

use Illuminate\Console\Command;

/**
 * Draws every active giveaway whose end time has passed. Registered to run every
 * minute by the extension's service provider (via the host's scheduler), and can
 * also be run by hand: `php artisan convoro:giveaways-draw`.
 */
class DrawDueCommand extends Command
{
    protected $signature = 'convoro:giveaways-draw';

    protected $description = 'Draw winners for any giveaways whose end time has passed';

    public function handle(): int
    {
        $n = Draw::drawDue();
        $this->info($n === 0 ? 'No giveaways were due to draw.' : "Drew {$n} giveaway(s).");

        return self::SUCCESS;
    }
}
