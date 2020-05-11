<?php

namespace Potelo\LaravelBlockBots\Contracts;

use Illuminate\Support\Facades\Auth;

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
}
