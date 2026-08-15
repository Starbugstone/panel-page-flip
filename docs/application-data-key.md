# Application data encryption key

`APP_DATA_KEY` protects Dropbox tokens and metadata-provider credentials at rest. It is a deployment secret, not a passphrase.

Generate exactly 32 random bytes, encoded as Base64 or 64 hexadecimal characters:

```bash
openssl rand -base64 32
# or
openssl rand -hex 32
```

Arbitrary strings and placeholders are rejected. Keep the production key outside the repository and back it up separately from the database. A database backup without this key cannot recover encrypted integration credentials.

Do not casually replace the key: existing ciphertext is authenticated with the old key. Rotation requires an explicit decrypt-with-old / encrypt-with-new migration before the old key is retired. The development and test keys committed in this repository are intentionally public and must never be reused in production.
