<?php

enum SafepayEndpoints: string
{
    case PRODUCTION_BASE_URL = 'https://getsafepay.com';
    case SANDBOX_BASE_URL = 'https://sandbox.api.getsafepay.com';
    case DEVELOPMENT_BASE_URL = 'https://dev.api.getsafepay.com';
    case CHECKOUT_ROUTE = '/checkout/pay';
    case TRANSACTION_ENDPOINT = '/order/payments/v3/';
    case PRODUCTION_API_URL = 'https://api.getsafepay.com/';
}