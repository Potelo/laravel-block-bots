<?php

namespace Potelo\LaravelBlockBots\Jobs;

use GuzzleHttp\Client;
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

    protected $ip;
    protected $user_agent;
    protected $config;
    protected $attemped_url;
    protected $action;


    /**
     * Checks whether the given IP address really belongs to a valid host or not
     *
     * @param $ip the IP address to check
     * @return bool true if the given IP address belongs to any of the valid hosts, otherwise false
     */
    public function __construct($ip, $user_agent, $action, $attemped_url=null)
    {
        $this->ip = $ip;
        $this->user_agent = $user_agent;
        $this->attemped_url = $attemped_url;
        $this->action = $action;
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
        $user_agent = $this->user_agent;
        $ip = $this->ip;
        $key_access_count = "block_bot:{$ip}";
        $number_of_requests = Redis::get($key_access_count);

        $host = strtolower(gethostbyaddr($ip));
        $messsage = "[Block-Bots] IP: {$ip}; After {$number_of_requests} requests , Host:{$host} \n  with User agent: {$user_agent};  was {$this->action}";

        $ip_info =  $this->config['ip_info_key'];

        if ($ip_info)
        {
            $client = new Client();
            $response = $client->get(
                "http://ipinfo.io/{$ip}" ,
                [
                    'query' => [
                        'token' => $ip_info,
                    ]
                ]
            );
            $json_response = json_decode($response->getBody(), true);
            if(array_key_exists('org', $json_response) && array_key_exists('city', $json_response) &&
                array_key_exists('region', $json_response) && array_key_exists('country', $json_response))
            {
                $org = $json_response["org"];
                $city = $json_response["city"];
                $region = $json_response["region"];
                $country = $json_response["country"];

                $messsage .= "Org: {$org} | city: {$city} | region: {$region} | country: {$country} ";
            }
        }

        if ($this->attemped_url){
            $messsage .= " when accessing the URL:{$this->attemped_url} ";
        }

        if (($this->action == 'GOOD_CRAWLER') || ($this->action == 'WHITELISTED'))
        {
            Log::stack($this->config['channels_info'])->info($messsage);
        }
        else{
            Log::stack($this->config['channels_info'])->error($messsage);
        }



    }

}
