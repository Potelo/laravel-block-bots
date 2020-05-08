<?php

namespace Potelo\LaravelBlockBots\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Redis;
use Potelo\LaravelBlockBots\Jobs\ProcessLogWithIpInfo;

class CheckIfBotIsReal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $client;
    protected $options;


    /**
     * Checks whether the given IP address really belongs to a valid host or not
     *
     * @param $this->client->ip the IP address to check
     * @return bool true if the given IP address belongs to any of the valid hosts, otherwise false
     */
    public function __construct($client, $allowedBots, $options)
    {
        $this->client = $client;
        $this->allowedBots = $allowedBots;
        $this->options = $options;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $found_bot_key = null;

        foreach (array_keys($this->allowedBots) as $bot) {
            if (strpos($this->client->userAgent, strtolower($bot)) !== false) {
                $found_bot_key = $bot;
                break;
            }
        }

        if (is_null($found_bot_key)) {
            throw new \InvalidArgumentException("I did not found {$found_bot_key} key");
        }

        // Lets remove from the pending list
        Redis::srem($this->options->pending_bots_key, $this->client->ip);
        if ($this->isValid($found_bot_key)) {
            Redis::sadd($this->options->whitelist_key, $this->client->ip);

            if ($this->options->log) {
                ProcessLogWithIpInfo::dispatch($this->client, 'GOOD_CRAWLER', $this->options);
            }
        } else {
            Redis::sadd($this->options->fake_bot_list_key, $this->client->ip);

            if ($this->options->log) {
                ProcessLogWithIpInfo::dispatch($this->client, 'BAD_CRAWLER', $this->options);
            }
        }
    }

    private function isValid($found_bot_key)
    {
        if (filter_var($this->client->ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $valid_host = strtolower($this->allowedBots[$found_bot_key]);

        // A wildcard will enable this user-agent to any IP
        if ($valid_host === '*') {
            return true;
        }

        $host = strtolower(gethostbyaddr($this->client->ip));
        $ipAfterLookup = gethostbyname($host);

        $hostIsValid = (strpos($host, $valid_host) !== false);

        return $hostIsValid && $ipAfterLookup === $this->client->ip;
    }
}
