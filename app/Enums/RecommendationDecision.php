<?php

namespace App\Enums;

enum RecommendationDecision: string
{
    case Buy = 'buy';
    case BuyIfPriceIsGood = 'buy_if_price_is_good';
    case ConsiderAlternatives = 'consider_alternatives';
    case Wait = 'wait';
    case DoNotBuy = 'do_not_buy';
}
