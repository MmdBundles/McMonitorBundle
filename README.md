# Minecraft Servers Monitor

Create your own service that makes regular requests to minecraft servers and sends the information to a webhook (your site).

This will prevent your site being blocked when making requests to servers.

## Install

* Add to `composer.json`

```json
"require": {
    ...
    "mmd/mc-monitor": "dev-master",
    "sensio/buzz-bundle": "dev-master",
    "neitanod/forceutf8": "dev-master",
    "xpaw/php-minecraft-query": "dev-master"
}
```

* Include bundle in `app/AppKernel.php`

```php
$bundles = array(
    ...
    new Mmd\Bundle\McMonitorBundle\MmdMcMonitorBundle(),
);
```

* Include routing in `app/config/routing.yml`

```yml
mmd_mc_monitor:
    resource: "@MmdMcMonitorBundle/Resources/config/routing.yml"
    prefix:   /mc-monitor
```

* Add parameters to `app/config/parameters.yml`

```yml
# The secret used in API requests
mmd.mc_monitor.secret: "my-secret"

# The url to your site (with server list) where monitoring will send servers status updates
mmd.mc_monitor.webhook: "https://my-site.com/monitoring-updates"
```

* Create database tables

```sh
php app/console doctrine:schema:update --force
```

## Configure

* Set crontab to execute command that checks servers status and send information to the webhook

```sh
cd /path/to/project/root/
sudo -u www-data php app/console mmd:mc-monitor:check 3
```

You can specify how many servers to check at once.

The servers will be ordered ascending by last checked time.

Run command as apache user `www-data` to prevent `Unable to write in the cache directory` error.
In this case the cron must be set as root user for the `sudo` command to work in background.

## Usage

* Add server ip to monitoring

```text
# Request
GET /mc-monitor/api/v1/<secret>/add/<ip>
```

```text
# Response
{"status":true, "message": "Server added"}
# or
{"status":false, "message": "Invalid ip"}
```

* In your application, you must handle monitoring server updates requests to the url set in the `mmd.mc_monitor.webhook` parameter

The monitoring will do requests in the following format

```text
https://site.com/secret-url-for-mc-monitor/?data=base64encoded({
    secret: "<secret-set-in-parameters>",
    servers: {
        "<server-ip>": {
            status: true, /* true=online, false=offline */
            data: {
                'hostname': 'Awesome minecraft server motd',
                'numplayers': 7,
                'maxplayers': 20,
                'version': '1.8'
            }
        },
        "<server-ip>": {...},
        ...
    }
})
```

* Remove a server from monitoring

There is no way to remove a server from monitoring directly.
This is done for cases when someone knows your secret,
he will not be able to remove all servers from monitoring.

A server can be removed from monitoring by sending a json response to the API,
when it will make a request to specified webhook url, in the following format:

```json
{"remove":["127.0.0.1:25565","192.168.1.100","<server-ip>"]}
```
