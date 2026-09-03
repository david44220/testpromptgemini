<?php

declare(strict_types=1);

namespace App\Enums;

enum LedgerAccountType: string
{
    case Asset = 'ASSET';
    case Liability = 'LIABILITY';
    case Equity = 'EQUITY';
    case Revenue = 'REVENUE';
    case Expense = 'EXPENSE';
}
