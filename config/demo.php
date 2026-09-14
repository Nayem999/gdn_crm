<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo data
    |--------------------------------------------------------------------------
    |
    | DemoDataSeeder invents customers, deals and tickets. It refuses to run in
    | production unless this is switched on, because a production database is
    | exactly where invented customers cause trouble — and because every demo
    | account it creates shares one obvious password.
    |
    */

    'allowed' => (bool) env('DEMO_DATA_ALLOWED', false),

];
