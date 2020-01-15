<?php

namespace Potelo\LaravelBlockBots\Events;

use Carbon\Carbon;
use Illuminate\Queue\SerializesModels;

class UserBlockedEvent
{
    use SerializesModels;

    public $user;

    /** @var integer */
    public $number_of_hits;

    /** @var Carbon */
    public $block_date;

    /**
     * Create a new event instance.
     *
     * @param $user
     * @param  integer  $number_of_hits
     * @param  Carbon  $block_date
     *
     * @return void
     */
    public function __construct($user, $number_of_hits, $block_date)
    {
        $this->user = $user;
        $this->number_of_hits = $number_of_hits;
        $this->block_date = $block_date;
    }
}
