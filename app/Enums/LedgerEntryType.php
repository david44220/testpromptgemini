<?php

declare(strict_types=1);

namespace App\Enums;

enum LedgerEntryType: string
{
    case Debit = 'DEBIT';
    case Credit = 'CREDIT';
}
