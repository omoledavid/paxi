<?php

namespace App\Enums;

enum TransactionType: string
{
    const WALLET_TRANSFER = 'Wallet Transfer';
    const WALLET_CREDIT = 'Wallet Credit';
    const ACCOUNT_UPGRADE = 'Account Upgrade';
}
