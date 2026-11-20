<?php

namespace App\Enums;

enum EscalationTrigger: string
{
    case CRITICAL = 'CRITICAL';
    case OVERDUE = 'OVERDUE';
    case MANUAL = 'MANUAL';
    case NO_OFFICER = 'NO_OFFICER';
    case TRIAGE = 'TRIAGE';
    case RULE = 'RULE';
}
