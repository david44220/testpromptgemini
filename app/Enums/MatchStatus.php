<?php

declare(strict_types=1);

namespace App\Enums;

enum MatchStatus: string
{
    case WaitingForOpponent = 'WAITING_FOR_OPPONENT';
    case Ready = 'READY';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case Disputed = 'DISPUTED';
}
