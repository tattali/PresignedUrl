# Upgrading from 1.0 to 1.1

1.1 keeps the 1.0 interfaces and public methods. Two things can affect an existing application:

- a signing secret under 32 bytes, or an unsafe signature length, now throws (first section below);
- some responses of `FileServer` (and of the Symfony and Laravel controllers) change. Read the other sections if your application relies on one of these behaviours.

URLs returned by `AwsS3Adapter` point to S3 directly and are not affected by the response changes.

## Signing secrets must be at least 32 bytes

What you see: building `Config` or `HmacSigner` throws `InvalidConfigurationException` with "The signing secret must be at least 32 bytes long. Generate one with bin2hex(random_bytes(32))." In the Symfony bundle the exception is thrown when the service is first built, not when the container is compiled. With Laravel, the fallback to `APP_KEY` keeps working with the default `AES-256-CBC` cipher (a 51-byte key), but an `AES-128-CBC` key (31 bytes) and a `PRESIGNED_STORAGE_SECRET` under 32 bytes throw.

`SignatureConfig` throws the same exception when `length` is under 16 or above the digest size of the algorithm (32 bytes for `sha256`, 64 for `sha512`), or when the algorithm is not one of `hash_hmac_algos()`. 1.0 gave the full digest for a `length` above the digest size; set `length` to the digest size to keep those signatures.

Why: anyone who guesses a short secret can sign URLs for any file in any bucket, and a short `length` lets signatures be brute-forced.

To fix it, generate a new secret and put it in your configuration (for example in `PRESIGNED_URL_SECRET` or `PRESIGNED_STORAGE_SECRET`):

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

A new secret makes every URL issued with the old one fail with 403, so issue the URLs again. With a secret of 32 bytes or more and a valid `length`, nothing changes: the signature format is the same and existing URLs keep verifying.

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

What you see: browsers use the `Content-Type` of the response and no longer guess it. A browser refuses to run a file loaded with `<script src="...">` whose type is not a JavaScript type, and to apply a stylesheet that is not `text/css`.

`LocalAdapter` detects the type from the file content and, when that gives only a generic type (`text/plain`, `application/octet-stream`, an empty-file type or a `text/x-*` source-code guess), uses the file extension, so `.js`, `.mjs` and `.css` files get `text/javascript` and `text/css`. Flysystem's own detector falls back to the extension for `text/plain` and empty files only, so a `FlysystemAdapter` bucket can still give `text/x-java` or `text/x-c++` for some scripts. If you use your own adapter, make sure its `mimeType()` returns the real type.

There is no option to remove the header.

## `LocalAdapter` gives a more precise `Content-Type` for some files

What you see: when `finfo` gives only a generic type for a file (`text/plain`, `application/octet-stream`, an empty-file type or a `text/x-*` guess), `LocalAdapter` now uses its extension. For example `.js`, `.mjs` and `.cjs` get `text/javascript`, `.css` gets `text/css`, `.json` and `.map` get `application/json`, `.md` gets `text/markdown`, `.csv` gets `text/csv`, `.ics` gets `text/calendar` and `.vtt` gets `text/vtt`. A type that `finfo` detects precisely is kept.

- Browsers may download `.md`, `.csv` or `.ics` files that they used to show as text.
- The default compression `types` include `text/javascript` and `text/css`, but not `text/csv`, `text/markdown` or `application/json`. Add the types you want compressed to `compression.types`.
- Uploaded `.js` and `.css` files can now be loaded by other pages as scripts and stylesheets. If buckets hold files uploaded by users and are served from your application's domain, add `js`, `mjs`, `cjs` and `css` to `blocked_extensions`, or serve them from a separate domain.

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
