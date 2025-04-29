<?php

namespace Potelo\LaravelBlockBots\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Potelo\LaravelBlockBots\Contracts\Configuration;

class ListNotified extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'block-bots:list-notified';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show the list of notified IPs with notified count';

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
        $this->info("List of notified IPs with notified count:");
        
        $keys = Redis::keys('block_bot:notified*');
        
        foreach ($keys as $key) {
            $key = str($key)->afterLast(':');
            $this->info($key . " : " . Redis::get("block_bot:notified:{$key}"));
        }
    }
}
