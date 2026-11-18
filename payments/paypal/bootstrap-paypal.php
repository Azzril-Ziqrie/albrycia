<?php
// bootstrap-paypal.php

require_once __DIR__ . '/../../config/database.php';

// Saklar PayPal: selama dimatikan, endpoint PayPal menolak semua permintaan.
if (!PAYPAL_ENABLED) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['error' => 'PayPal is currently unavailable.']));
}
require_once __DIR__ . '/../../vendor/autoload.php';

use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;

$paypalClient = PaypalServerSdkClientBuilder::init()
    ->clientCredentialsAuthCredentials(
        ClientCredentialsAuthCredentialsBuilder::init(
            PAYPAL_CLIENT_ID,
            PAYPAL_CLIENT_SECRET
        )
    )
    ->environment(
        PAYPAL_MODE === 'sandbox'
            ? Environment::SANDBOX
            : Environment::PRODUCTION
    )
    ->build();
