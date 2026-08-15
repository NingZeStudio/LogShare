<?php

namespace Handler;

class LimitsHandler extends \Handler
{
    public function handle(): void
    {
        $this->validateMethod('GET');

        $config = \Config::Get('storage');

        $this->respondJson([
            'storageTime' => $config['storageTime'],
            'maxLength' => $config['maxLength'],
            'maxLines' => $config['maxLines']
        ]);
    }
}
