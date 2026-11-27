<?php

namespace Potelo\LaravelBlockBots\Commands;

use Illuminate\Console\Command;
use Potelo\LaravelBlockBots\Services\BotIpResolver;

class RefreshBotIps extends Command
{
    protected $signature = 'block-bots:refresh-ips {--due : Refresh only new or expired snapshots}';
    protected $description = 'Queue refreshes for configured bot IP feeds';

    public function handle(BotIpResolver $resolver)
    {
        $count = 0;
        foreach ($resolver->providers() as $provider) {
            foreach ($provider->sources() as $bot => $source) {
                if ($provider->queueRefresh($bot, !$this->option('due'))) {
                    $count++;
                }
            }
        }
        $this->info('Queued bot IP refreshes: ' . $count);
        return 0;
    }
}
