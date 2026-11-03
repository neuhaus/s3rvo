<?php

namespace S3Gateway\Storage;

/** Optional direct, known-length uploads; validation can fail on the EOF read. */
interface StreamingStorage extends Storage
{
    public function canStreamUploads(): bool;

    /**
     * Consume input at its current position without closing it. Read and
     * validate EOF before publishing any object; late validation failures
     * must leave the previous object intact. Length counts decoded bytes.
     * @return array{size:int,mtime:int,mime:string,etag:string,metadata:array<string,string>}|null
     */
    public function putObjectFromStreamingBody(string $bucket, string $key, $input, int $length, ?ObjectMetadata $metadata = null): ?array;
}
