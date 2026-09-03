<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditStatus: string
{
    case Pending = 'PENDING';
    case Passed = 'PASSED';
    case Failed = 'FAILED';
    case Forfeit = 'FORFEIT';
}
