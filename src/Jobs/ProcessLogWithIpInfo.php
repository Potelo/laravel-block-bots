<?php

namespace Potelo\LaravelBlockBots\Jobs;

use Illuminate\Bus\Queueable;
use GuzzleHttp\Client as HTTP;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessLogWithIpInfo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $action;
    protected $client;
    protected $options;
    protected $accessLimit;


    /**
     * Checks whether the given IP address really belongs to a valid host or not
     * @param $client
     * @param $action
     * @param $options
     * @param $accessLimit
     */
    public function __construct($client, $action, $options = null, $accessLimit = null)
    {
        $this->action = $action;
        $this->client = $client;
        $this->options = $options;
        if (!is_null($accessLimit)) {
            $this->accessLimit = $accessLimit;
        }
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $hits = Redis::get($this->client->key);
        $host = strtolower(gethostbyaddr($this->client->ip));

        if (!empty($this->accessLimit)) {
            $message = "[Block-Bots] IP: {$this->client->ip}; After {$hits}/{$this->accessLimit} requests, Host: {$host} \n  with User agent: {$this->client->userAgent};  was {$this->action}";
        } else {
            $message = "[Block-Bots] IP: {$this->client->ip}; After {$hits} requests, Host: {$host} \n  with User agent: {$this->client->userAgent};  was {$this->action}";
        }

        if ($this->options->ip_info_key) {
            $http = new HTTP();
            $response = $http->get(
                "http://ipinfo.io/{$this->client->ip}",
                [
                    'query' => [
                        'token' => $this->options->ip_info_key,
                    ]
                ]
            );

            $json_response = json_decode($response->getBody(), true);

            if (
                array_key_exists('org', $json_response) && array_key_exists('city', $json_response) &&
                array_key_exists('region', $json_response) && array_key_exists('country', $json_response)
            ) {
                $org = $json_response["org"];
                $city = $json_response["city"];
                $region = $json_response["region"];
                $country = $json_response["country"];

                $message .= "Org: {$org} | city: {$city} | region: {$region} | country: {$country} ";
            }
        }

        if ($this->client->url) {
            $message .= " when accessing the URL: {$this->client->url} ";
        }

        if (($this->action === 'WHITELISTED') || ($this->action === 'GOOD_CRAWLER')) {
            Log::stack($this->options->channels_info)->info($message);
        } else {
            Log::stack($this->options->channels_info)->error($message);
        }
    }
}
