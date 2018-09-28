<?php

namespace Potelo\LaravelBlockBots\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class CheckIfBotIsReal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $ip;

    protected $user_agent;

    protected $config;


    /**
     * Checks whether the given IP address really belongs to a valid host or not
     *
     * @param $ip the IP address to check
     * @return bool true if the given IP address belongs to any of the valid hosts, otherwise false
     */
    public function __construct($ip, $user_agent)
    {
        $this->ip = $ip;
        $this->user_agent = $user_agent;
        $this->config = config('block-bots');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        //
        $allowed_bots = $this->config['allowed_crawlers'];
        $user_agent = strtolower($this->user_agent);
        $ip = $this->ip;
        $found_bot_key = null;
        $log_blocked_requests = $this->config['log_blocked_requests'];

        foreach (array_keys($allowed_bots) as $bot) {
            if (strpos($user_agent, strtolower($bot)) !== false) {
                $found_bot_key = $bot;
                break;
            }
        }

        if(is_null($found_bot_key))
        {
            throw new \InvalidArgumentException("I did not found {$found_bot_key} key");
        }

        $is_valid = $this->isValid($this->ip, $found_bot_key,  $allowed_bots);

        // Lets remove from the pending list
        $key_pending_bot = "block_bot:pending_bots";
        Redis::srem($key_pending_bot, $ip);

        if($is_valid){
            $key_whitelist = "block_bot:whitelist";
            Redis::sadd($key_whitelist, $ip);

            if ($log_blocked_requests){
                \Potelo\LaravelBlockBots\Jobs\ProcessLogWithIpInfo::dispatch($ip, $user_agent, 'GOOD_CRAWLER');
            }

        }
        else{
            $key_fake_bot = "block_bot:fake_bots";
            Redis::sadd($key_fake_bot, $ip);

            if ($log_blocked_requests){
                \Potelo\LaravelBlockBots\Jobs\ProcessLogWithIpInfo::dispatch($ip, $user_agent, 'BAD_CRAWLER');
            }
        }

    }

    private function isValid($ip, $found_bot_key, $allowed_bots)
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $valid_host = strtolower($allowed_bots[$found_bot_key]);

        // A wildcard will enable this user-agent to any IP
        if($valid_host == '*')
            return true;

        $host = strtolower(gethostbyaddr($ip));
        $ipAfterLookup = gethostbyname($host);
        $hostIsValid = (strpos($host, $valid_host) !== false);
        return $hostIsValid && $ipAfterLookup === $ip;
    }
}
