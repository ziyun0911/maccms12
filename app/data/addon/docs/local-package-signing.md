# Local addon package signing (optional)

Place your RS256 **public** key at:

`app/data/addon/local_public.pem`

Or set in site config (runtime merge, do not commit secrets into repo defaults):

```php
// conceptually under maccms.php 'addon' key — merge at runtime if needed
'addon' => [
    'require_local_signature' => '0', // '1' to force signed zips
    'local_public_key' => '',         // PEM string or absolute path to .pem
],
```

## Package files

Inside the zip root (same level as `info.ini`):

### `package.manifest.json`

```json
{
  "name": "demo",
  "version": "1.0.0",
  "files": {
    "info.ini": "<sha256 hex of file>",
    "Demo.php": "<sha256 hex of file>"
  }
}
```

- `files` keys are paths relative to zip root.
- Do **not** include `package.sig` / `package.manifest.json` in the signed `files` map (verifier skips them if present).
- Keys are sorted (`ksort`) before signing.
- Verifier hashes every listed file **and** rejects any on-disk file not listed (except the two signature materials). Extra files break the integrity guarantee.

### `package.sig`

Base64-encoded RSA-SHA256 signature over:

```text
json_encode($files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
```

after `ksort($files)`.

## Notes

- **Default is off** (`require_local_signature` unset/`0`): unsigned zips are still accepted after Zip Slip / path / extension checks. Installing a zip means executing PHP under `addons/` — treat uploads as trusted code. For production hardening, set `require_local_signature=1` and deploy `local_public.pem`.
- Zip entries are path-normalized (no `./` / empty segments). These top-level entries are rejected (`AddonSecureInstaller::$blockedPrefixes`): `application/`, `static/`, `thinkphp/`, `extend/`, `vendor/`, `template/`, `public/`, `runtime/`, `addons/` — the list is inherited from maccms10, where installing an addon could overlay those directories onto the site root. In maccms12 install/enable only ever writes inside `addons/<name>/`, so a top-level `app/` entry is not blocked but also cannot reach the live `app/` tree; it just sits unused under the plugin dir.
- If a zip **includes** signature files, verification is mandatory (and a public key must be present), even when the force flag is off.
- Full marketplace PKI remains a separate backlog item.
