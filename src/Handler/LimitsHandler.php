<?php

namespace Handler;

class LimitsHandler extends \Handler
{
    public function handle(): void
    {
        try {
            $this->validateMethod('GET');
        } catch (\ApiError $e) {
            $e->output();
        }

        $config = \Config::Get('storage');

        $this->respondJson([
            'storageTime' => $config['storageTime'],
            'maxLength' => $config['maxLength'],
            'maxLines' => $config['maxLines']
        ]);
    }
}
