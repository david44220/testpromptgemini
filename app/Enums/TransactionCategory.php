<?php

declare(strict_types=1);

namespace App\Enums;

enum TransactionCategory: string
{
    case Deposit = 'DEPOSIT';
    case Withdrawal = 'WITHDRAWAL';
    case EscrowLock = 'ESCROW_LOCK';
    case EscrowRelease = 'ESCROW_RELEASE';
    case WagerWin = 'WAGER_WIN';
    case PlatformFee = 'PLATFORM_FEE';
    case BonusGrant = 'BONUS_GRANT';
}
