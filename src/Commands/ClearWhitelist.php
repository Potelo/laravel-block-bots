<?php

namespace Potelo\LaravelBlockBots\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ClearWhitelist extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'block-bots:clear-whitelist';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear the White-list';

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
        $whitelist = Redis::del($key_whitelist);
        $this->info("Whitelist cleared");


    }
}
