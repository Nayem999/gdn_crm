<?php

namespace App\Domain\Ingestion\Enums;

use App\Domain\Ingestion\IngestSignature;

/**
 * Why a delivery was turned away, and with what status.
 *
 * One enum rather than scattered `response()->json(..., 401)` calls, so the
 * status a given failure produces is decided once and can be read at a glance —
 * and so the tests assert against the same declaration the endpoint uses.
 *
 * **An unknown source and a disabled one both answer 404**, with no detail.
 * Anything else tells a caller which uuids exist. Past that point the answers
 * are specific, because reaching them means already holding a live source
 * address, and an integrator debugging a clock-drift problem should not have to
 * guess between "my signature is wrong" and "my clock is wrong".
 */
enum IngestRefusal: string
{
    case UnknownSource = 'unknown_source';
    case AddressNotAllowed = 'address_not_allowed';
    case PayloadTooLarge = 'payload_too_large';
    case MissingKey = 'missing_key';
    case InvalidKey = 'invalid_key';
    case MissingSignature = 'missing_signature';
    case InvalidSignature = 'invalid_signature';
    case StaleTimestamp = 'stale_timestamp';
    case Replayed = 'replayed';

    public function status(): int
    {
        return match ($this) {
            self::UnknownSource => 404,
            self::AddressNotAllowed => 403,
            self::PayloadTooLarge => 413,
            self::MissingKey,
            self::InvalidKey,
            self::MissingSignature,
            self::InvalidSignature,
            self::StaleTimestamp => 401,
            // Not an authentication failure: the request was perfectly valid,
            // and we have already seen it.
            self::Replayed => 409,
        };
    }

    /**
     * What the caller is told. Enough for somebody building the integration to
     * fix it, and nothing about whether a source exists.
     */
    public function message(): string
    {
        return match ($this) {
            self::UnknownSource => 'Not found.',
            self::AddressNotAllowed => 'This address is not allowed to post to this source.',
            self::PayloadTooLarge => 'The payload is larger than this source accepts.',
            self::MissingKey => 'The '.IngestSignature::KEY_HEADER.' header is required.',
            self::InvalidKey => 'That key is not valid for this source.',
            self::MissingSignature => 'The '.IngestSignature::HEADER.' header is required.',
            self::InvalidSignature => 'The signature did not verify.',
            self::StaleTimestamp => 'The signed timestamp is outside the accepted window. Check the sending clock.',
            self::Replayed => 'This request has already been received.',
        };
    }

    /**
     * Whether this refusal is somebody failing to authenticate, as opposed to
     * getting the shape of the request wrong.
     *
     * The brief asks for failed authentication attempts to be logged with the
     * address they came from; this is what decides which ones those are.
     */
    public function isAuthFailure(): bool
    {
        return in_array($this, [
            self::MissingKey,
            self::InvalidKey,
            self::MissingSignature,
            self::InvalidSignature,
            self::StaleTimestamp,
        ], true);
    }
}
