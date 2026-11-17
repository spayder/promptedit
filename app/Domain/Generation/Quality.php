<?php

declare(strict_types=1);

namespace App\Domain\Generation;

enum Quality: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
