<?php

namespace Shirahcan\AiClient\Laravel\Dictation;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Shirahcan\AiClient\Exceptions\AiUnavailableException;
use Shirahcan\AiClient\Exceptions\AllProvidersFailedException;
use Throwable;

/**
 * POST <prefix> (multipart): one phrase from the shared ai-ui recorder (16 kHz WAV), its words back.
 *
 * Answers in the estate's API shape, so every product's API client reads it the same way:
 *   200 {success: true, message, data: {text, seconds_left_today}}
 *   404 off, 422 bad input, 429 today's minutes used (expected, quiet), 503 no speech-to-text key.
 * Nothing is sent on the person's behalf: the words land in their box.
 */
final class DictationController extends Controller
{
    public function __invoke(Request $request, DictationTranscriber $transcriber, DictationSettings $settings): JsonResponse
    {
        if (! $settings->enabled()) {
            return $this->fail('Dictation is not available yet.', 404);
        }

        try {
            $data = $request->validate([
                // One phrase: the recorder cuts at 25 s, which is ~800 KB of 16 kHz WAV.
                'audio' => ['required', 'file', 'max:2048', 'mimetypes:audio/wav,audio/x-wav,audio/wave,audio/ogg,audio/mpeg,audio/mp4,audio/webm,video/webm'],
                'surface' => array_merge(['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,23}$/'],
                    $settings->surfaces() === [] ? [] : [\Illuminate\Validation\Rule::in($settings->surfaces())]),
                'language' => ['nullable', 'string', 'max:8'],
                'terms' => ['nullable', 'array', 'max:40'],
                'terms.*' => ['string', 'max:60'],
            ], [
                'audio.required' => 'No recording arrived. Try the microphone again.',
                'audio.max' => 'That piece of speech is too long to send at once.',
                'audio.mimetypes' => 'That recording format is not supported.',
            ]);

            $out = $transcriber->transcribe(
                $request->user(),
                $request->file('audio'),
                $data['surface'],
                $data['language'] ?? null,
                $data['terms'] ?? [],
            );

            return response()->json(['success' => true, 'message' => 'Success', 'data' => $out]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (DictationAllowanceUsed $e) {
            return $this->fail($e->getMessage(), 429);
        } catch (AllProvidersFailedException|AiUnavailableException $e) {
            report($e);

            return $this->fail('Dictation is not available right now. Type instead, and try the microphone again later.', 503);
        } catch (Throwable $e) {
            // Reported, so the product's error surface has a record to offer a ticket from.
            report($e);

            return $this->fail('We could not turn your speech into text just now. Please try again.', 500);
        }
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}
