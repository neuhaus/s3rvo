# Storage backends

`Storage` defines bucket and object operations. `FileStorage` remains the default,
preserving the existing filesystem layout and metadata. Controllers use backend
metadata and streams rather than filesystem paths.

To select another backend, set `STORAGE_CLASS` in the configuration's `[general]`
section to an autoloadable class implementing `Storage`. Its constructor must
accept no required arguments. Applications can also inject a backend into
`Router`. No configuration changes are needed for filesystem storage.

## Streams and metadata

- `putObjectFromStream` reads from the input's current position and leaves it open.
  It returns committed object metadata, or `null` on failure.
- `openObject` returns a readable stream positioned at the requested offset, or
  `false` if it cannot open the object. The caller closes the stream. It need not
  be seekable; the response reads at most the requested length.
- Metadata contains `size`, `mtime`, `mime`, and an unquoted `etag`. Bucket creation
  timestamps also come from the backend.
- The `etag` is an opaque validator supplied by the backend. `FileStorage`
  returns the content MD5, or S3's multipart form
  `md5(concatenated binary part MD5s)-{part count}`; its part ETags are the parts'
  MD5s. Other backends may return version identifiers that are not checksums.
- `putObjectFromStream`, `copyObject` and `createMultipartUpload` accept optional
  `ObjectMetadata` (the upload's Content-Type and `x-amz-meta-*` user metadata).
  Store it with the object and return it through `getObjectMeta`: the Content-Type as
  `mime`, user metadata as `metadata`. `copyObject` without replacement metadata copies
  the source's; a copy onto itself with replacement metadata changes only the metadata.
- Filesystem writes use a temporary sibling file and rename it into place after
  copying the input successfully.

Backends receive verified, staged request bodies by default. The base storage
interface alone does not opt a backend into the direct-upload path below.

## Multipart uploads

Backends can implement `MultipartStorage` to support multipart uploads and expired
upload cleanup. Other backends return S3 `NotImplemented` (HTTP 501) for multipart
operations. `cleanup_multipart.php` delegates expiration and removal to the selected
backend.

## Direct uploads

A backend may implement `StreamingStorage` and return true from
`canStreamUploads()` to accept known-length, one-pass HTTP uploads via
`putObjectFromStreamingBody()`. The gateway defers body consumption, decodes
aws-chunked framing and verifies size limits, supported checksums and signatures
while the backend reads. Validation can fail on the final EOF read. The backend
must validate that EOF before publishing the object and preserve any existing
object on failure. Implementations that use framed network writes must withhold
their final publication bytes until that read has succeeded.

Other backends, unknown decoded lengths, and multipart/control requests retain
the verified staging path. Authentication without a declared payload hash may
also require staging before the request signature can be checked. Request
streaming additionally depends on PHP's SAPI and reverse-proxy buffering settings.
