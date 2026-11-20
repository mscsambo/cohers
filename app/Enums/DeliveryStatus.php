<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case PENDING = 'PENDING';
    case SENT = 'SENT';
    case SIMULATED = 'SIMULATED';
    case FAILED = 'FAILED';
    case UNDELIVERABLE = 'UNDELIVERABLE';
}
