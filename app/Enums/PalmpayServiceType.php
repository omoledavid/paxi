<?php

namespace App\Enums;

enum PalmpayServiceType: string
{
    case AIRTIME = 'airtime';
    case DATA = 'data';
    case BETTING = 'betting';
    case BANK_TRANSFER = 'bank_transfer';
    case PAYOUT = 'payout';
}
