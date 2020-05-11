<?php

namespace Potelo\LaravelBlockBots\Jobs;

use GuzzleHttp\Client as HTTP;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class ProcessLogWithIpInfo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $action;
    protected $client;
    protected $options;


    /**
     * Checks whether the given IP address really belongs to a valid host or not
     *
     * @param $ip the IP address to check
     * @return bool true if the given IP address belongs to any of the valid hosts, otherwise false
     */
    public function __construct($client, $action, $options = null)
    {
        $this->action = $action;
        $this->client = $client;
        $this->options = $options;
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

        $messsage = "[Block-Bots] IP: {$this->client->ip}; After {$hits} requests, Host: {$host} \n  with User agent: {$this->client->userAgent};  was {$this->action}";

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

                $messsage .= "Org: {$org} | city: {$city} | region: {$region} | country: {$country} ";
            }
        }

        if ($this->client->url) {
            $messsage .= " when accessing the URL: {$this->client->url} ";
        }

        if (($this->action === 'WHITELISTED') || ($this->action === 'GOOD_CRAWLER')) {
            Log::stack($this->options->channels_info)->info($messsage);
        } else {
            Log::stack($this->options->channels_info)->error($messsage);
        }
    }
}
