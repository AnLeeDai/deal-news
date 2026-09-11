<?php

namespace App;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class DockerSmokeJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put('docker-smoke-job-completed', true, 60);
    }
}
