<?php

namespace Shirahcan\AiClient\Exceptions;

/**
 * ai-service answered, and REFUSED the request. The service is healthy; the
 * request was wrong.
 *
 * ⚠ WHY THIS EXISTS. Every 4xx that was not one of the known error codes used
 * to arrive as `AiUnavailableException` with the message "ai-service returned
 * an error (HTTP 422)". That sent a reader to check whether the service was up -
 * it was - instead of at the message the service had actually written, which
 * usually says exactly what to fix: a soft spend cap set above the hard one, a
 * product name that is not in the service's closed set, an empty retire list
 * that would have wiped a knowledge index.
 *
 * ⚠ IT EXTENDS `AiUnavailableException` ON PURPOSE, AND THAT IS NOT LAZINESS.
 * Four products already catch that type to degrade gracefully, and a refusal
 * escaping those blocks as an unhandled error would be a regression delivered
 * by a release whose point was better reporting. Subclassing keeps every
 * existing `catch` working unchanged while letting a caller that cares - an
 * admin console showing an operator what the service said - catch the narrower
 * type first and report the real message with a 422.
 *
 * ⚠ A 5xx IS NOT THIS. An outage stays `AiUnavailableException`, because the
 * remedies are opposite: one is "fix the request", the other is "the service is
 * down, degrade".
 */
class AiRequestRefusedException extends AiUnavailableException
{
    public function __construct(
        string $message,
        /** The HTTP status the service answered with. */
        public readonly int $status = 422,
        /** The service's own machine-readable `error` code, when it gave one. */
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }
}
