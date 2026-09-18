<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Report;

enum DetailLevel: string
{
    case Full = 'full';
    case Count = 'count';
    case Unavailable = 'unavailable';
}
