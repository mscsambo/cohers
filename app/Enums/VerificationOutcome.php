<?php

namespace App\Enums;

enum VerificationOutcome: string
{
    case VERIFIED = 'VERIFIED';
    case REJECTED = 'REJECTED';
    case DUPLICATE = 'DUPLICATE';
    case NEEDS_INFO = 'NEEDS_INFO';
    case ESCALATED = 'ESCALATED';
}
