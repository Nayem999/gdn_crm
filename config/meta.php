<?php

return [

    /*
    |--------------------------------------------------------------------------
    | No credentials live here
    |--------------------------------------------------------------------------
    |
    | The app ID, the app secret, the webhook verify token and the dataset ID
    | are configured in Settings -> Meta and stored encrypted in the database.
    | They are deliberately absent from this file and from .env, so there is one
    | place to look, one place to rotate them, and nothing to leak through a
    | config dump, a committed .env.example, or a deployment script.
    |
    | What is left here is operational: which Graph version to call and how to
    | behave when Meta is slow or throttling. None of it is secret, and none of
    | it identifies this installation.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Graph API version
    |--------------------------------------------------------------------------
    |
    | The version this application ships against, and the ONE place it is
    | written — every call goes through MetaGraphClient, which reads it from
    | here. An administrator can override it in Settings without a deploy, and
    | that override is validated against the same `v<major>.<minor>` shape.
    |
    | Meta retires a version roughly two years after release and then starts
    | refusing calls to it, so this wants checking before each go-live.
    |
    */

    'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),

    'graph_url' => env('META_GRAPH_URL', 'https://graph.facebook.com'),

    /*
    |--------------------------------------------------------------------------
    | HTTP behaviour
    |--------------------------------------------------------------------------
    |
    | Meta rate limits per app and per business, and answers a throttled call
    | with 429 and a code of its own. Retrying immediately makes that worse, so
    | the client retries only what a retry could fix and gives up rather than
    | queueing behind a limit that resets on the hour.
    |
    */

    'timeout' => (int) env('META_HTTP_TIMEOUT', 20),

    'connect_timeout' => (int) env('META_HTTP_CONNECT_TIMEOUT', 10),

    'retries' => (int) env('META_HTTP_RETRIES', 3),

    /*
     * Milliseconds before the first retry. Doubled each attempt.
     */
    'retry_base_delay' => (int) env('META_HTTP_RETRY_DELAY', 500),

    /*
     * Stop calling when Meta reports this much of the rate limit consumed. The
     * headers say how close we are; carrying on to 100% gets the whole app
     * throttled, which affects every other integration too.
     */
    'usage_ceiling' => (int) env('META_USAGE_CEILING', 90),

    /*
     * Pages a single paginated read will follow before stopping. A safety net
     * against a cursor loop, not a business limit.
     */
    'max_pages' => (int) env('META_MAX_PAGES', 50),

];
