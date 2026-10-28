<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | SIP Deployer provisions the OpenSIPS and Asterisk nodes and hands out their manifests and
    | credentials. The token is issued to this application's API client in the deployer, with the
    | nodes:read and credentials:read abilities, and only works from that client's addresses.
    */
    'sip_deployer' => [
        'url' => env('SIP_DEPLOYER_URL'),
        'token' => env('SIP_DEPLOYER_TOKEN'),
    ],

    // every tenant's SIP domain is <slug>.<suffix>; a wildcard DNS record points it at the nodes
    'sip' => [
        'tenant_domain_suffix' => env('SIP_TENANT_DOMAIN_SUFFIX', 'sip.example.com'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
