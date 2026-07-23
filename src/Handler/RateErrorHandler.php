<?php

namespace Handler;

class RateErrorHandler extends \Handler
{
    public function handle(): void
    {
        $error = new \ApiError(
            429,
            "Unfortunately you have exceeded the rate limit for the current time period. Please try again later."
        );
        $error->output();
    }
}
