<?php

namespace Potelo\LaravelBlockBots\Contracts;

use Illuminate\Support\Facades\Auth;

class Client
{
    public $id;
    public $ip;
    public $userAgent;

    public function __construct($request)
    {
        $this->id = Auth::check() ? Auth::id() : $this->ip;
        $this->ip = $request->getClientIp();
        $this->userAgent = $request->header('User-Agent');
        $this->key = "block_bot:{$this->id}";
        $this->logKey = "block_bot:notified:{$this->ip}";
        $this->url = substr($request->fullUrl(), strlen($request->getScheme() . "://"));
    }
}
