# Encryption key management

Applies to the [database driver](db-driver.md) only. With Vault's SSH signer there is no long-lived secret to protect.

## Which key is in use

By default, one derived from `APP_KEY`:

```
HKDF-SHA256(APP_KEY, info = "librenms-webterm/credentials/v1")
```

Deriving rather than reusing means a compromise of `APP_KEY` — which appears in `.env`, in deployment tooling and in support bundles — does not directly hand over device credentials. It raises the cost; it does not eliminate the risk, because anyone who can read `APP_KEY` can perform the same derivation.

## A dedicated key

For real separation, so that `APP_KEY` and the credential key can live in different places with different access:

```bash
# in LibreNMS's .env
WEBTERM_CREDENTIAL_KEY=base64:<32 random bytes, base64-encoded>
```

Generate one with:

```bash
php -r 'echo "base64:", base64_encode(random_bytes(32)), "\n";'
```

Then re-encrypt existing rows onto it:

```bash
./lnms webterm:credentials:rekey --dry-run
./lnms webterm:credentials:rekey
```

## Rotation

Rotate on a schedule you can actually keep, and whenever someone with access to the key leaves.

```bash
# 1. See what would change
./lnms webterm:credentials:rekey --dry-run

# 2. Set the new key in .env, keeping the old value to hand

# 3. Migrate
./lnms webterm:credentials:rekey --from="<the old key>"

# 4. Confirm nothing is left behind
./lnms webterm:doctor
```

Only discard the old key once step 4 is clean.

## Backups

!!! warning "Your database backups contain credentials"

    Encrypted, but decryptable by anyone holding the key. If backups and `.env` are stored in the same place, with the same access, the encryption is doing less than it appears.

    Keep them separate, or use Vault, where there is nothing in the database to steal.

## What is stored

| | |
|---|---|
| Encrypted | The password, or private key and passphrase |
| Plaintext | The SSH username, the key id, the cipher name, the public-key fingerprint |

The fingerprint is stored so the admin UI can show which key is configured without decrypting anything.
