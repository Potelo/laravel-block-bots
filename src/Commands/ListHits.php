<?php

namespace Potelo\LaravelBlockBots\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Potelo\LaravelBlockBots\Contracts\Configuration;

class ListHits extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'block-bots:list-hits';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show the list of IPs with hits count';

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
        $this->info("List of IPs hits:");
        
        $keys = Redis::keys('block_bot:hits*');
        
        foreach ($keys as $key) {
            $key = str($key)->afterLast(':');
            $this->info($key . " : " . Redis::get("block_bot:hits:{$key}"));
        }
    }
}
