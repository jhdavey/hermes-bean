<?php

namespace App\Jobs;

use App\Services\BeanMemoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExtractBeanMemoryFromTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $memoryEventId) {}

    public function handle(BeanMemoryService $memory): void
    {
        $memory->processEvent($this->memoryEventId);
    }
}
