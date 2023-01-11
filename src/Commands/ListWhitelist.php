<?php

namespace Potelo\LaravelBlockBots\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Potelo\LaravelBlockBots\Contracts\Configuration;

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
     * @var \Potelo\LaravelBlockBots\Contracts\Configuration
     */
    private $options;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->options = new Configuration();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $whitelist = Redis::smembers($this->options->whitelist_key);

        $this->info("List of IPs whitelisted:");
        foreach ($whitelist as $ip) {
            $this->info($ip);
        }
    }
}
