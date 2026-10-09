# Evolution X CDN operations

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