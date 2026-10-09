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