<?php

namespace Potelo\LaravelBlockBots\Events;

use Carbon\Carbon;
use Illuminate\Queue\SerializesModels;

class BotBlockedEvent
{
    use SerializesModels;

    public $ip;

    /** @var integer */
    public $number_of_hits;

    /** @var Carbon */
    public $block_date;

    /**
     * Create a new event instance.
     *
     * @param $ip
     * @param  integer  $number_of_hits
     * @param  Carbon  $block_date
     *
     * @return void
     */
    public function __construct($ip, $number_of_hits, $block_date)
    {
        $this->ip = $ip;
        $this->number_of_hits = $number_of_hits;
        $this->block_date = $block_date;
    }
}
