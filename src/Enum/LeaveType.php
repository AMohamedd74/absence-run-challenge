<?php

declare(strict_types=1);

namespace App\Enum;

enum LeaveType: string
{
    case VACATION = 'vacation';
    case SICK = 'sick';
    case UNPAID = 'unpaid';
    case SPECIAL = 'special';
}
