<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| Published so the two public capture paths can be reached from the customer's
| own website. Everything else in the application is same-origin and stays that
| way — a blanket "*" on every path would put the authenticated application
| behind the same permissive header.
|
| Credentials are deliberately off. These endpoints authenticate with the token
| in their path, not with a cookie, so there is nothing for a browser to send —
| and "allow any origin" together with "send credentials" is the combination
| browsers refuse for good reason.
|
*/

return [

    'paths' => ['f/*', 'c/*'],

    'allowed_methods' => ['*'],

    // A public capture endpoint is reached from whichever site embeds it, and
    // the application has no way of knowing which sites those are.
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
