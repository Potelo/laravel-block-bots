<?php

namespace Potelo\LaravelBlockBots\Abstracts;

use Potelo\LaravelBlockBots\Contracts\Configuration;
use Potelo\LaravelBlockBots\Contracts\Client;
use Carbon\Carbon;

abstract class AbstractBlockBots
{
    private $allowedBots = [];
    protected $request;
    protected $limit;
    protected $frequency;
    protected $options;
    protected $client;
    protected $timeOutAt;
    protected $hits = 1;

    public function __construct()
    {
        $this->options = new Configuration();
    }

    public function setUp($request, $limit, $frequency)
    {
        $this->setRequest($request);
        $this->setLimit($limit);
        $this->setFrequency($frequency);
        $this->setTimeOut($frequency);
        $this->setClient();
    }

    protected function beforeHandle()
    {
    }

    protected function setTimeOut()
    {
        switch ($this->frequency) {
            case 'hourly':
                $this->timeOutAt = Carbon::now()->addHour(1)->timestamp;
                break;
            case 'daily':
                $this->timeOutAt = Carbon::tomorrow()->startOfDay()->timestamp;
                break;
            case 'monthly':
                $this->timeOutAt = (new Carbon('first day of next month'))->firstOfMonth()->startOfDay()->timestamp;
                break;
            case 'annually':
                $this->timeOutAt = (new Carbon('next year'))->startOfYear()->firstOfMonth()->startOfDay()->timestamp;
                break;
        }
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

    abstract protected function countHits();

    abstract protected function isAllowed();

    abstract protected function notAllowed();
}
