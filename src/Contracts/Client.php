<?php

namespace Potelo\LaravelBlockBots\Contracts;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Potelo\LaravelBlockBots\Jobs\ProcessLogWithIpInfo;

class Client
{
    public $id;
    public $ip;
    public $userAgent;
    public $key;
    public $logKey;
    public $url;

    public function __construct($request)
    {
        $this->ip = $request->getClientIp();
        $this->id = Auth::check() ? Auth::id() : $this->ip;
        $this->userAgent = $request->header('User-Agent');
        $this->key = "block_bot:{$this->id}";
        $this->logKey = "block_bot:notified:{$this->ip}";
        $this->url = substr($request->fullUrl(), strlen($request->getScheme() . "://"));
    }

    /**
     * Returns the value of the access counter
     *
     * @return void
     */
    public function countHits($incrementHits = true, $frequency = 'daily')
    {
        if (!Redis::exists($this->key)) {
            if ($incrementHits) {
                Redis::set($this->key, 1);
                Redis::expireat($this->key, $this->getTimeoutAt($frequency));
            }
            return 1;
        }

        if ($incrementHits) {
            return Redis::incr($this->key);
        } else {
            return Redis::get($this->key);
        }
    }

    /**
     * @return void
     */
    public function logDisallowance($options, $limit)
    {
        if (!Redis::exists($this->logKey)) {
            Redis::set($this->logKey, 1);
            Redis::expireat($this->logKey, $this->timeOutAt);
            ProcessLogWithIpInfo::dispatch($this->client, "BLOCKED", $options, $limit);
        }
    }

    /**
     * @return float|int|string|void
     */
    protected function getTimeoutAt($frequency)
    {
        switch ($frequency) {
            case 'hourly':
                return Carbon::now()->addHour(1)->timestamp;
            case 'daily':
                return Carbon::tomorrow()->startOfDay()->timestamp;
            case 'monthly':
                return (new Carbon('first day of next month'))->firstOfMonth()->startOfDay()->timestamp;
            case 'annually':
                return (new Carbon('next year'))->startOfYear()->firstOfMonth()->startOfDay()->timestamp;
        }
    }
}
