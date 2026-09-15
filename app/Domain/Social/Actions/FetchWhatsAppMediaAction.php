<?php

namespace App\Domain\Social\Actions;

use App\Domain\Meta\Graph\MetaApiException;
use App\Domain\Meta\Graph\MetaGraphClient;
use App\Domain\Meta\Models\WhatsAppPhoneNumber;
use App\Domain\Social\Models\SocialMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bringing a WhatsApp attachment onto the private disk.
 *
 * Meta does not send the file. It sends a media **id**, and fetching the bytes
 * takes two calls — one for a URL, one for the content — and the URL is good for
 * about five minutes and needs the account token. So storing the URL and showing
 * it in the thread would produce attachments that work while somebody is looking
 * and are broken by the time anybody comes back to them.
 *
 * Which is why this exists and why it runs on the queue rather than inside the
 * webhook: the message is already threaded and readable, and the photograph
 * catching up a second later is far better than a delivery that times out
 * because Meta was slow with a video.
 *
 * **Size is capped.** Meta permits up to 100MB for a document, and a CRM that
 * pulled one down per message would fill a disk quietly. Above the ceiling the
 * message keeps its caption and says what it could not fetch.
 */
class FetchWhatsAppMediaAction
{
    /**
     * The most we will pull down for one attachment.
     *
     * Sixteen megabytes covers every photograph, voice note and ordinary
     * document a customer sends; beyond that is video, and a thread is not a
     * video library.
     */
    public const MAX_BYTES = 16 * 1024 * 1024;

    public function __construct(private readonly MetaGraphClient $client) {}

    /**
     * @return bool Whether anything was stored.
     */
    public function __invoke(SocialMessage $message): bool
    {
        if ($message->hasStoredAttachment()) {
            // Already here. A replayed delivery must not fetch it twice, and
            // `singleFile()` would replace the stored copy with an identical one
            // for no reason.
            return false;
        }

        $media = $this->descriptor($message);

        if ($media === null) {
            return false;
        }

        $token = $this->token($message);

        if ($token === null) {
            return false;
        }

        try {
            $url = $this->url((string) $media['media_id'], $token);

            if ($url === null) {
                return false;
            }

            return $this->store($message, $url, $token, $media);
        } catch (Throwable $exception) {
            // An attachment that could not be fetched is not a failed delivery:
            // the message itself is threaded and readable, and throwing here
            // would mark the whole webhook failed and invite a replay that
            // duplicates nothing useful.
            Log::warning('A WhatsApp attachment could not be fetched.', [
                'message' => $message->getKey(),
                'reason' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * What the handler recorded about the attachment.
     *
     * @return array<string, mixed>|null
     */
    private function descriptor(SocialMessage $message): ?array
    {
        $media = $message->attachments ?? [];
        $first = is_array($media[0] ?? null) ? $media[0] : null;

        if ($first === null) {
            return null;
        }

        $id = $first['media_id'] ?? null;

        return is_string($id) && $id !== '' ? $first : null;
    }

    /**
     * The token for the number this message's conversation belongs to.
     */
    private function token(SocialMessage $message): ?string
    {
        $accountId = $message->conversation?->channel_account_id;

        if ($accountId === null) {
            return null;
        }

        $token = WhatsAppPhoneNumber::query()
            ->with('businessAccount')
            ->where('phone_number_id', $accountId)
            ->first()?->businessAccount?->access_token;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Meta's short-lived URL for a media id.
     *
     * @throws MetaApiException
     */
    private function url(string $mediaId, string $token): ?string
    {
        $response = $this->client->get($mediaId, [], $token);

        $url = $response['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function store(SocialMessage $message, string $url, string $token, array $media): bool
    {
        // Not through MetaGraphClient: this is a download from a lookaside host
        // rather than a Graph call, it answers with bytes rather than JSON, and
        // it still needs the bearer token.
        $response = Http::withToken($token)->timeout(60)->get($url);

        if ($response->failed()) {
            return false;
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BYTES) {
            $message->forceFill([
                'error' => 'The attachment was too large to store ('.round(strlen($body) / 1048576, 1).'MB).',
            ])->save();

            return false;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'wa-media-');

        if ($temporary === false) {
            return false;
        }

        file_put_contents($temporary, $body);

        try {
            $message->addMedia($temporary)
                // A name of ours, not theirs. A filename from a stranger has no
                // business deciding a path, and Meta passes the customer's
                // through untouched.
                ->usingFileName($this->fileName($media, $response->header('Content-Type')))
                ->toMediaCollection('attachment');
        } finally {
            // addMedia moves the file, but a failure part-way leaves it behind.
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }

        return true;
    }

    /**
     * A safe stored name, built rather than trusted.
     *
     * @param  array<string, mixed>  $media
     */
    private function fileName(array $media, ?string $contentType): string
    {
        $mime = is_string($media['mime_type'] ?? null) ? $media['mime_type'] : (string) $contentType;
        $extension = match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'mp4') => 'mp4',
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'mpeg') => 'mp3',
            default => 'bin',
        };

        return 'whatsapp-'.Str::random(16).'.'.$extension;
    }
}
