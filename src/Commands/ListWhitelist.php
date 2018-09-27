<?php

namespace Potelo\LaravelBlockBots\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ListWhitelist extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'block-bots:list-whitelist';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show the list of IPs that are Whitelisted';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //
        $key_whitelist = "block_bot:whitelist";
        $whitelist = Redis::smembers($key_whitelist);
        $this->info("List of IPs whitelisted:");
        foreach ($whitelist as $ip)
        {
            $this->info($ip);
        }

    }
}
