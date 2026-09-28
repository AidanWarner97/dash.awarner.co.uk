# Catalogue storage migration

The dashboard stores catalogue metadata in MariaDB and publishes `catalogue/tiles.json` as a rollback-compatible copy. Image object keys retain the existing `catalogue/images/...` paths. Bunny Storage is authoritative for image bytes once migration is complete.

## Dashboard environment

Add these values to the dashboard `.env`:

```dotenv
BUNNY_STORAGE_ZONE="your-storage-zone"
BUNNY_STORAGE_ACCESS_KEY="your-storage-zone-password"
BUNNY_STORAGE_HOST="uk.storage.bunnycdn.com"
BUNNY_CDN_HOST="your-pull-zone.b-cdn.net"
```

Use the Storage Zone password, not the account API key. `BUNNY_STORAGE_HOST` must match the zone's Bunny region; it accepts either the bare hostname or the full Storage Zone endpoint copied from Bunny and defaults to `storage.bunnycdn.com`.

The existing `TILEIMAGEGEN_DB_*` settings remain the MariaDB source. The dashboard creates `<TILEIMAGEGEN_DB_TABLE_PREFIX>catalogue` and `<TILEIMAGEGEN_DB_TABLE_PREFIX>catalogue_assets` automatically.

## Generator environment

Add these values to the Tile Image Generator `.env`:

```dotenv
BUNNY_CDN_HOST="your-pull-zone.b-cdn.net"
CATALOGUE_TABLE="tig_catalogue"
CATALOGUE_CACHE_DIR="/var/cache/tile-image-gen/catalogue"
CATALOGUE_CACHE_MAX_BYTES="5368709120"
```

`CATALOGUE_TABLE` must equal the dashboard table prefix followed by `catalogue`. The cache directory must be writable by PHP. A cache miss downloads from Bunny; during migration only, a failed download falls back to the matching local file. After local originals are removed, a missing CDN object produces a clear generation failure.

## Migration

Run from the dashboard repository. Start with a small verified batch:

```bash
php bin/catalogue-storage.php status
php bin/catalogue-storage.php migrate --limit=10
php bin/catalogue-storage.php status
```

Then run the resumable full migration:

```bash
php bin/catalogue-storage.php migrate
```

Each file is SHA-256 hashed, uploaded, downloaded through the authenticated Bunny Storage API, compared byte-for-byte, and marked `ready`. Re-running the command skips matching verified objects. Avoid `--no-verify` for the production migration.

New dashboard uploads go directly to Bunny once all three required Bunny values are present. Catalogue reads prefer MariaDB, while the JSON file remains available for rollback.

## Cutover and local deletion

Confirm the dashboard previews, catalogue endpoint, and generation path before deleting anything. Generate and review a deletion manifest:

```bash
php bin/catalogue-storage.php deletion-manifest --manifest=/secure/catalogue-deletion.json
```

Deletion requires that exact manifest and an explicit confirmation flag. Every local file is re-hashed immediately before removal:

```bash
php bin/catalogue-storage.php delete-local --manifest=/secure/catalogue-deletion.json --confirm
```

Keep the manifest through the rollback window. Old `tileimagegen.uk/catalogue/images/...` URLs are routed through `catalogue-image.php`: they serve local files before Bunny is configured and issue temporary CDN redirects afterward.
