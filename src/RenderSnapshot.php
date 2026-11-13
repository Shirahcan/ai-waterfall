<?php

namespace Shirahcan\AiClient;

/**
 * One drawn picture from ai-service's snapshot (1.70.0, shared program E2d), or why there is none.
 *
 * Never thrown, always returned: a product deciding what to do with a card needs the reason, not an exception.
 *  - `unavailable`: busy, no browser on this box, the page could not be drawn, or ai-service unreachable.
 *    Retryable: keep the last good picture and try again later.
 *  - `host_not_registered`, `not_ready`, `too_large`, `invalid`: a setup or design fault. Not retryable.
 */
final class RenderSnapshot
{
    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        /** The picture's raw bytes (decoded), null when it failed. */
        public readonly ?string $bytes,
        /** `png` or `jpeg`. */
        public readonly ?string $format,
        public readonly int $width,
        public readonly int $height,
        /** The JPEG quality used, null for PNG. */
        public readonly ?int $quality,
        public readonly ?string $error,
        public readonly ?string $message,
    ) {}

    /** @param array{image?: string, format?: string, width?: int, height?: int, quality?: ?int} $answer */
    public static function fromAnswer(array $answer): self
    {
        $bytes = base64_decode((string) ($answer['image'] ?? ''), true);

        if ($bytes === false || $bytes === '') {
            return self::failed(self::UNAVAILABLE, 'ai-service answered without a picture.');
        }

        return new self($bytes, (string) ($answer['format'] ?? 'png'), (int) ($answer['width'] ?? 0),
            (int) ($answer['height'] ?? 0), isset($answer['quality']) ? (int) $answer['quality'] : null, null, null);
    }

    public static function failed(string $error, string $message): self
    {
        return new self(null, null, 0, 0, null, $error, $message);
    }

    public function ok(): bool
    {
        return $this->bytes !== null;
    }

    /** Busy or unavailable: worth trying again later. A setup or design fault is not. */
    public function retryable(): bool
    {
        return $this->error === self::UNAVAILABLE;
    }

    public function extension(): ?string
    {
        return match ($this->format) {
            'png' => 'png',
            'jpeg' => 'jpg',
            default => null,
        };
    }

    public function mimeType(): ?string
    {
        return match ($this->format) {
            'png' => 'image/png',
            'jpeg' => 'image/jpeg',
            default => null,
        };
    }
}
