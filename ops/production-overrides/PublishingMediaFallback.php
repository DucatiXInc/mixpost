<?php

namespace Inovector\Mixpost\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inovector\Mixpost\Models\Media;
use RuntimeException;

/** Recover a provider-rejected cloud asset without replacing or deleting its source. */
class PublishingMediaFallback
{
    public function recover(Media $media, ?string $conversion = null): array
    {
        return Cache::lock('mixpost:publishing-media-fallback:'.$media->id, 180)->block(5, function () use ($media, $conversion) {
            $media->refresh();
            $asset = $conversion === null
                ? ['disk' => $media->disk, 'path' => $media->path, 'size' => $media->size]
                : $media->getConversion($conversion);
            if ($asset && $asset['disk'] === 'public' && str_starts_with($asset['path'], 'publish-recovery/')) {
                $saved = collect($media->data['publishing_fallbacks'] ?? [])->last(fn ($item) => $item['path'] === $asset['path'] && $item['conversion'] === $conversion);
                $path = Storage::disk('public')->path($asset['path']);
                if (! $saved || ! is_file($path) || hash_file('sha256', $path) !== $saved['sha256']) {
                    throw new RuntimeException('Retained recovery media could not be verified.');
                }
                $this->verifyPublic(Storage::disk('public')->url($asset['path']), (int) $asset['size'], $saved['sha256']);
                return ['media_id' => (int) $media->id, 'conversion' => $conversion, 'sha256' => $saved['sha256']];
            }
            if (! $asset || config('filesystems.disks.'.$asset['disk'].'.driver') !== 's3') {
                throw new RuntimeException('The rejected asset is no longer on cloud storage.');
            }
            $size = (int) ($asset['size'] ?? 0);
            if ($size <= 0 || $size > 512 * 1024 ** 2) {
                throw new RuntimeException('Recovery requires a known, bounded source size.');
            }
            $identity = hash('sha256', json_encode([$media->id, $conversion, $asset], JSON_THROW_ON_ERROR));
            $target = 'publish-recovery/'.$media->uuid.'/'.$identity.'/'.basename($asset['path']);
            $disk = Storage::disk('public');
            $path = $disk->path($target);
            $receiptPath = storage_path('app/peachy-publish-recovery/'.$identity.'.json');
            $receipt = is_file($receiptPath) ? json_decode(file_get_contents($receiptPath), true) : null;
            if (! $receipt || ! is_file($path) || ($receipt['bytes'] ?? 0) !== $size
                || ($receipt['sha256'] ?? '') !== hash_file('sha256', $path)) {
                $free = disk_free_space(storage_path('app'));
                if ($free === false || $free < 5 * 1024 ** 3 + 2 * $size) {
                    throw new RuntimeException('Insufficient space for verified publishing recovery.');
                }
                $this->directory(dirname($path));
                $temporary = tempnam(dirname($path), '.incoming-');
                try {
                    $remote = Storage::disk($asset['disk']);
                    $object = $remote->getClient()->getObject([
                        'Bucket' => $remote->getConfig()['bucket'], 'Key' => $asset['path'],
                        '@http' => ['connect_timeout' => 5, 'timeout' => 45, 'read_timeout' => 10], '@retries' => 0,
                    ]);
                    if ((int) $object['ContentLength'] !== $size) {
                        throw new RuntimeException('Remote asset size changed.');
                    }
                    $out = fopen($temporary, 'wb');
                    try {
                        $bytes = 0;
                        while (! $object['Body']->eof()) {
                            $chunk = $object['Body']->read(1024 * 1024);
                            if ($chunk === '' && ! $object['Body']->eof()) { throw new RuntimeException('Storage read stalled.'); }
                            $bytes += strlen($chunk);
                            if ($bytes > $size || fwrite($out, $chunk) !== strlen($chunk)) {
                                throw new RuntimeException('Incomplete storage recovery write.');
                            }
                        }
                        if ($bytes !== $size || ! fflush($out) || ! fsync($out)) {
                            throw new RuntimeException('Incomplete storage recovery.');
                        }
                    } finally { fclose($out); $object['Body']->close(); }
                    $etag = trim((string) ($object['ETag'] ?? ''), '"');
                    if (preg_match('/^[a-f0-9]{32}$/i', $etag) && strtolower($etag) !== md5_file($temporary)) {
                        throw new RuntimeException('Remote asset checksum mismatch.');
                    }
                    $receipt = ['bytes' => $size, 'sha256' => hash_file('sha256', $temporary), 'source' => $asset, 'conversion' => $conversion];
                    chmod($temporary, 0644);
                    if (! rename($temporary, $path)) { throw new RuntimeException('Cannot retain recovered media.'); }
                    $this->directory(dirname($receiptPath));
                    $receiptTemp = tempnam(dirname($receiptPath), '.receipt-');
                    try {
                        file_put_contents($receiptTemp, json_encode($receipt, JSON_THROW_ON_ERROR), LOCK_EX);
                        chmod($receiptTemp, 0600);
                        if (! rename($receiptTemp, $receiptPath)) { throw new RuntimeException('Cannot retain recovery evidence.'); }
                    } finally { if (is_file($receiptTemp)) { unlink($receiptTemp); } }
                } finally { if (is_file($temporary)) { unlink($temporary); } }
            }
            $url = $disk->url($target);
            $this->verifyPublic($url, $size, $receipt['sha256']);
            DB::transaction(function () use ($media, $conversion, $asset, $target, $receipt) {
                $current = Media::withoutGlobalScopes()->lockForUpdate()->findOrFail($media->id);
                if ($current->workspace_id !== $media->workspace_id || $current->disk !== $media->disk
                    || $current->size !== $media->size || $current->mime_type !== $media->mime_type
                    || $current->path !== $media->path || $current->conversions !== $media->conversions || $current->data !== $media->data) {
                    throw new RuntimeException('Media changed during recovery.');
                }
                $data = $current->data ?? [];
                $data['publishing_fallbacks'][] = ['at' => gmdate('c'), 'source' => $asset, 'conversion' => $conversion,
                    'path' => $target, 'sha256' => $receipt['sha256']];
                $changes = ['data' => $data];
                if ($conversion === null) { $changes += ['disk' => 'public', 'path' => $target]; }
                else {
                    $changes['conversions'] = array_map(fn ($item) => ($item['name'] ?? '') === $conversion
                        ? array_replace($item, ['disk' => 'public', 'path' => $target]) : $item, $current->conversions);
                }
                $current->forceFill($changes)->save();
            });
            return ['media_id' => (int) $media->id, 'conversion' => $conversion, 'sha256' => $receipt['sha256']];
        });
    }

    protected function verifyPublic(string $url, int $size, string $sha256): void
    {
        $response = Http::withOptions(['stream' => true, 'allow_redirects' => false, 'read_timeout' => 10])
            ->connectTimeout(5)->timeout(45)->get($url);
        if ($response->status() !== 200) { throw new RuntimeException('Public fallback is unavailable.'); }
        $body = $response->toPsrResponse()->getBody();
        try {
            $hash = hash_init('sha256'); $bytes = 0;
            while (! $body->eof()) {
                $chunk = $body->read(1024 * 1024);
                if ($chunk === '' && ! $body->eof()) { throw new RuntimeException('Public verification stalled.'); }
                $bytes += strlen($chunk);
                if ($bytes > $size) { throw new RuntimeException('Public fallback size mismatch.'); }
                hash_update($hash, $chunk);
            }
            if ($bytes !== $size || hash_final($hash) !== $sha256) {
                throw new RuntimeException('Public fallback checksum mismatch.');
            }
        } finally { $body->close(); }
    }

    private function directory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create publishing recovery directory.');
        }
    }
}
