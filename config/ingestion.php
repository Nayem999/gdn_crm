<?php

return [

    /*
    |--------------------------------------------------------------------------
    | When a delivery is processed
    |--------------------------------------------------------------------------
    |
    | A webhook is written down and answered immediately — Meta and everyone
    | else retries anything slower than a few seconds — and the work of turning
    | it into a lead or a message happens afterwards. This decides what
    | "afterwards" means.
    |
    | "after_response" does it in the same PHP process once the sender has its
    | 200, so an installation with no queue worker still threads its messages.
    | That is most installations: a CRM on shared hosting has no daemon and
    | often no cron, and the failure it produces is silent — the delivery log
    | fills up and the inbox never moves.
    |
    | "queue" hands it to a worker instead, which is better where one is
    | actually running: failures retry, a burst of a thousand leads does not
    | ride on web requests, and the work is visible in the queue.
    |
    */

    'process' => env('INGESTION_PROCESS', 'after_response'),

];
