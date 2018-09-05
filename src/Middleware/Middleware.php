<?php

namespace Potelo\LaravelBlockBots;

abstract class Middleware
{
    public function enabled()
    {
        //return config('firewall.enabled');
        return true;
    }
}
