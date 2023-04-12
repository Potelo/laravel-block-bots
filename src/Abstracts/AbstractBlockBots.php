<?php

namespace Potelo\LaravelBlockBots\Abstracts;

use Potelo\LaravelBlockBots\Contracts\Client;
use Potelo\LaravelBlockBots\Contracts\Configuration;

abstract class AbstractBlockBots
{
    private $allowedBots = [];
    protected $request;
    protected $limit;
    protected $frequency;
    protected $options;
    protected $client;
    protected $hits = 1;
    protected $incrementHits = true;

    public function __construct()
    {
        $this->options = new Configuration();
    }

    public function setUp($request, $limit, $frequency)
    {
        $this->setRequest($request);
        $this->setLimit($limit);
        $this->setFrequency($frequency);
        $this->setClient();
    }

    protected function beforeHandle()
    {
    }

    protected function setRequest($request)
    {
        $this->request = $request;
    }

    protected function setLimit($limit)
    {
        $this->limit = $limit;
    }

    protected function setFrequency($frequency)
    {
        $this->frequency = $frequency;
    }

    protected function setClient()
    {
        $this->client = new Client($this->request);
    }

    final protected function getAllowedBots()
    {
        if ($this->options->use_default_allowed_bots) {
            return array_merge($this->options->allowed_bots, $this->allowedBots);
        }
        return $this->allowedBots;
    }

    final protected function setAllowedBots($bots)
    {
        $this->allowedBots = $bots;
    }

    final protected function isLimitExceeded()
    {
        return $this->hits > $this->limit;
    }

    final protected function isTheFirstOverflow()
    {
        return $this->hits === $this->limit + 1;
    }

    abstract protected function isAllowed();

    abstract protected function notAllowed();

    /**
     * @return void
     */
    protected function canIncrementHits()
    {
        $this->incrementHits = true;
    }

    /**
     * @return void
     */
    protected function dontIncrementHits()
    {
        $this->incrementHits = false;
    }
}
