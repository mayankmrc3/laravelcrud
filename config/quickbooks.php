<?php


return [
    'client_id'        => env('QUICKBOOKS_CONSUMER_KEY'),
    'client_secret'     => env('QUICKBOOKS_CONSUMER_SECRET'),
    'access_token'        => env('QUICKBOOKS_ACCESS_TOKEN'),
    'access_token_secret' => env('QUICKBOOKS_ACCESS_TOKEN_SECRET'),
    'customer_id'         => env('QUICKBOOKS_CUSTOMER_ID'),
    'redirect_uri'         => 'http://local.quickbook.com/',
    'authorization_endpoint'         => env('QBO_AUTH_URL'),
    //'scope' =>  'com.intuit.quickbooks.accounting openid profile email phone address', //com.intuit.quickbooks.accounting'
    'scope' => 'com.intuit.quickbooks.payment',
    'endpoint' => env('QUICKBOOKS_ENDPOINT', 'https://sandbox-quickbooks.api.intuit.com'),
    'baseUrl' =>'https://sandbox-quickbooks.api.intuit.com',
];