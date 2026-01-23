<?php

namespace App\Enums;

enum NelloBytesServiceType: string
{
    case BETTING = 'betting';
    case EPIN = 'epin';
    case SMILE = 'smile';
    case SPECTRANET = 'spectranet';
    case DATA = 'data';
    case ELECTRICITY = 'electricity';
    case CABLETV = 'cabletv';
}
