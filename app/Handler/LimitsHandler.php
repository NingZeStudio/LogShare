<?php

namespace App\Handler;

class LimitsHandler extends \App\Handler
{
    public function handle(): void
    {
        $this->validateMethod('GET');

        $config = \App\Config::Get('storage');

        $this->respondJson([
            'storageTime' => $config['storageTime'],
            'maxLength' => $config['maxLength'],
            'maxLines' => $config['maxLines']
        ]);
    }
}
