<?php

enum SafepayEndpoints: string
{
    case PRODUCTION_URL = 'https://getsafepay.com';
    case SANDBOX_BASE_URL = 'https://sandbox.api.getsafepay.com';
    case DEVELOPMENT_BASE_URL = 'https://dev.api.getsafepay.com';
    case CHECKOUT_ROUTE = '/checkout/pay';
    case TRANSACTION_ENDPOINT = '/order/payments/v3/';
    case PRODUCTION_BASE_URL = 'https://api.getsafepay.com/';
    case TOKEN_ENDPOINT = '/client/passport/v1/token';
    case META_DATA_ENDPOINT = '/order/payments/v3';
    
}