<?php

namespace App\Usage\Domain;

enum UsageMetric: string
{
    case Tokens = 'tokens';
    case Cost = 'cost';
}
