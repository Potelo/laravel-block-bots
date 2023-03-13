<?php

namespace Potelo\LaravelBlockBots\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Potelo\LaravelBlockBots\Contracts\Configuration;

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
        Redis::del($this->options->whitelist_key);
        $this->info("Whitelist cleared");
    }
}
