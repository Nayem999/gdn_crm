<?php

use App\Domain\Ingestion\WebhookEventName;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| What a delivery was
|--------------------------------------------------------------------------
|
| The log's Event column. Every sender says what kind of thing it sent, and
| none of them say it the same way, so these are the shapes that actually
| arrive rather than a format this application would prefer.
|
*/

test('a WhatsApp delivery is named by the field it changed', function () {
    $body = json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => '1405962320928347',
            'changes' => [[
                'field' => 'messages',
                'value' => ['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.X']]],
            ]],
        ]],
    ]);

    // Not "page" — the envelope's `object` says what it is about, and the
    // change says what happened, which is the column somebody is scanning.
    expect(WebhookEventName::fromPayload($body))->toBe('messages');
});

test('a lead is named leadgen, the way Meta names it', function () {
    $body = json_encode([
        'object' => 'page',
        'entry' => [[
            'changes' => [[
                'field' => 'leadgen',
                'value' => ['leadgen_id' => '1234', 'form_id' => '5678'],
            ]],
        ]],
    ]);

    expect(WebhookEventName::fromPayload($body))->toBe('leadgen');
});

test('a Messenger delivery is named by what is in the item', function (string $key, string $expected) {
    $body = json_encode([
        'object' => 'page',
        'entry' => [[
            // Messenger has no `field`: the kind is the key beside the
            // routing information.
            'messaging' => [[
                'sender' => ['id' => '9876'],
                'recipient' => ['id' => '106069340959538'],
                'timestamp' => 1758326400000,
                $key => ['mid' => 'm_X'],
            ]],
        ]],
    ]);

    expect(WebhookEventName::fromPayload($body))->toBe($expected);
})->with([
    ['message', 'message'],
    ['postback', 'postback'],
    ['delivery', 'delivery'],
    ['read', 'read'],
]);

test('an ordinary webhook is named by whatever key it used', function (string $key) {
    $body = json_encode([$key => 'invoice.paid', 'data' => ['id' => 42]]);

    expect(WebhookEventName::fromPayload($body))->toBe('invoice.paid');
})->with(['event', 'event_type', 'eventType', 'type', 'topic', 'action']);

test('a header beats the body, because it is what the vendor documents', function () {
    $body = json_encode(['event' => 'ignored', 'ref' => 'refs/heads/master']);

    $request = Request::create('/api/ingest/x', 'POST', [], [], [], [
        'HTTP_X_GITHUB_EVENT' => 'push',
    ], $body);

    expect(WebhookEventName::for($request, $body))->toBe('push');
});

test('a delivery that says nothing about itself is left unnamed', function (string $body) {
    // Null means "did not say", which the log shows as blank. Inventing a
    // name here would put a fact in the column that no sender stated.
    expect(WebhookEventName::fromPayload($body))->toBeNull();
})->with([
    'not JSON at all' => 'id=42&name=Ada',
    'empty' => '',
    'JSON without a kind' => '{"id":42,"name":"Ada"}',
    'a JSON array' => '[{"event":"buried"}]',
]);

test('a name is trimmed to something that fits a column and a CSV row', function () {
    $long = str_repeat('a', 120);

    expect(WebhookEventName::fromPayload(json_encode(['event' => $long])))
        ->toHaveLength(WebhookEventName::MAX)
        // A newline would break the row this ends up in when the log is
        // exported, and the sender chooses this string.
        ->and(WebhookEventName::fromPayload(json_encode(['event' => "order\ncreated"])))
        ->toBe('order created');
});
