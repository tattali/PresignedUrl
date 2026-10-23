# Upgrading from 1.0 to 1.1

1.1 keeps the 1.0 API: code and configuration written for 1.0 keep working. Some responses of `FileServer` (and of the Symfony and Laravel controllers) change. Read the sections below if your application relies on one of these behaviours.

URLs returned by `AwsS3Adapter` point to S3 directly and are not affected.

## HTML, SVG, XML and JavaScript files are downloaded instead of displayed

What you see: opening the URL of an `.html`, `.htm`, `.xhtml`, `.svg`, `.svgz`, `.xml`, `.xsl`, `.js` or `.mjs` file, or of a file with an HTML, XML, SVG or JavaScript media type, downloads the file. The response has `Content-Disposition: attachment` and `Content-Security-Policy: sandbox`, whatever `content_disposition` is set to. An SVG shown with `<img src="...">` still displays.

Why: an HTML or SVG file uploaded by a user and displayed inline runs its scripts on your domain.

To display these files again, only when every file in your buckets is trusted or the files are served from a separate, cookieless domain:

```php
new ServingConfig(allowUnsafeInline: true);
```

```yaml
# config/packages/presigned_url.yaml
presigned_url:
    serving:
        allow_unsafe_inline: true
```

```php
// config/presigned-url.php
'serving' => [
    'allow_unsafe_inline' => true,
],
```

## Every file is sent with `X-Content-Type-Options: nosniff`

What you see: browsers use the `Content-Type` of the response and no longer guess it. `LocalAdapter` detects the type from the file content, and most `.js` files are detected as `text/plain`. A browser refuses to run such a file loaded with `<script src="...">`.

There is no option to remove the header. To serve scripts, use a `FlysystemAdapter` bucket (Flysystem falls back to the file extension when the content gives `text/plain`) or an adapter that returns the stored `Content-Type`.

## File names in `Content-Disposition` are encoded

What you see: control characters are removed from the file name. In the `filename` parameter, `"`, `\`, `%`, `/` and non-ASCII characters are replaced with `_`, and the UTF-8 name is added as `filename*=UTF-8''...`. Browsers use `filename*`, so downloaded files keep their name. A client that reads only `filename` gets the underscores.

## Gzip follows `Accept-Encoding`

What you see: a client that does not send `Accept-Encoding: gzip` gets the file uncompressed. In 1.0 it got gzip bytes without `Content-Encoding` and could not read them. A client that accepts gzip gets `Content-Encoding: gzip`, the compressed `Content-Length` and an ETag ending in `-gzip`, so copies it has cached are checked again once. Compressible files are sent with `Vary: Accept-Encoding`.

To never compress, set `compression.enabled` to `false` and let the web server compress responses.

## Range requests follow RFC 9110

What you see:

- `Range: bytes=-500` returns the last 500 bytes of the file. 1.0 returned the first ones.
- A range that starts after the end of the file, `bytes=-0` and any range on an empty file get `416 Range Not Satisfiable` with `Content-Range: bytes */<size>`. 1.0 sent the whole file with 200.
- When `If-Range` does not match the ETag or `Last-Modified` of the file, the whole file is sent with 200.

If you call `FileServer` yourself: `FileResponse::getBody()` returns a stream for 206 responses, as it already did for 200 responses. Read it with `stream_get_contents()`. `FileResponse::send()` and the Symfony and Laravel controllers already handle streams.

## `LocalAdapter` rejects symbolic links that leave the bucket

What you see: a path that goes through a symbolic link pointing outside the bucket directory, to a file or to a directory, answers 400 Bad Request. `exists()`, `read()`, `readStream()`, `size()`, `mimeType()` and `lastModified()` throw `InvalidPathException` for it. Symbolic links that stay inside the bucket keep working, and so does a bucket path that is itself a symbolic link.

There is no option to follow links outside the bucket. Move the files into the bucket directory, or register their directory as a bucket of its own.
