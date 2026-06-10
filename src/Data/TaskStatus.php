<?php

declare(strict_types=1);

namespace Desalort\Orchestrator\Data;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done    = 'done';
    case Failed  = 'failed';
    case Skipped = 'skipped';   // alguna dependencia falló
}
