<?php

namespace App\Handler;

class RateErrorHandler extends \App\Handler
{
    public function handle(): void
    {
        $error = new \App\ApiError(
            429,
            "Unfortunately you have exceeded the rate limit for the current time period. Please try again later."
        );
        $error->output();
    }
}
