# Evolution X CDN operations

## Dashboard cache

The overview cache is enabled automatically. In production, give the dashboard an explicit cache directory that persists across releases:

```bash
sudo install -d -o www-data -g www-data -m 0750 /var/cache/awarner-dashboard
```

Set these values in the deployed dashboard `.env`:

```dotenv
DASHBOARD_CACHE_DIR=/var/cache/awarner-dashboard
EVOLUTION_CDN_OVERVIEW_CACHE_SECONDS=60
```

Replace `www-data` with the PHP-FPM pool user when different. The overview TTL accepts 10 to 3600 seconds. Health checks use the same directory with a fixed 30-second TTL.

Verify the effective configuration as the PHP-FPM user:

```bash
sudo -u www-data php -r 'require "includes/evolutioncdn.php"; echo dashboard_cache_directory(), PHP_EOL; var_export(is_writable(dashboard_cache_directory())); echo PHP_EOL;'
```

## Minute runner

The dashboard has one CLI entry point for feedback notifications, newsletters, and Evolution X CDN automations. Run it once every minute as the dashboard application user:

```cron
* * * * * /usr/bin/php /path/to/dash.awarner.co.uk/cron.php >> /path/to/dash.awarner.co.uk/cron.log 2>&1
```

Do not add the individual Evolution X CDN scripts to crontab. Enable them and set their five-part cron expressions on **Evolution X CDN > Automations**. The minute runner uses a process lock, prevents duplicate scheduled starts within a minute, and records output and exit status in the CDN database.

The CDN database user needs `CREATE`, `SELECT`, `INSERT`, and `UPDATE` permissions for the dashboard automation tables.

## Bunny Storage

Configure the Storage Zone, regional API hostname, region, CDN hostname, and Storage Zone access key on **Evolution X CDN > Settings**. Use the Storage Zone password, not the Bunny account API key.

Bucket uploads are limited to 512 MB through the dashboard. Larger release files should continue through the CDN automation workers.

## Health monitoring

The dashboard uses `https://cdn.evolution-x.org/api/health` when Cloudflare permits machine requests. If the endpoint is challenged, the monitor automatically runs direct checks for MariaDB, Bunny Storage, source files, manifests, workers, and PHP instead.

For full origin health data without weakening the public Cloudflare policy, configure an internal endpoint under **Evolution X CDN > Settings > Health Monitoring**, for example `http://127.0.0.1/api/health`. The internal web server must route that address to the Evolution X CDN application.