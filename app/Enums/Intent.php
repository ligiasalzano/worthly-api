<?php

namespace App\Enums;

enum Intent: string
{
    case BuyDecision = 'buy_decision';
    case Compare = 'compare';
    case SpecLookup = 'spec_lookup';
    case Unknown = 'unknown';
}
