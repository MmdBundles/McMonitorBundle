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
    "neitanod/forceutf8": "dev-master"
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

* Set crontab to execute command that checks servers status and calls the webhook

```sh
cd /path/to/project/root/
sudo -u www-data php app/console mmd:mc-monitor:check-next-server
```

Run command as apache user `www-data` to prevent `Unable to write in the cache directory` error

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
https://site.com/secret-url-for-mc-monitor/?data={
    secret: "<secret-set-in-parameters>",
    ip: "<server-ip>",
    status: true, /* true=online, false=offline */
    data: {
        info: {...} /* http://wiki.vg/Query#Full_stat */
    }
}
```

* Remove a server from monitoring

There is no way to remove a server from monitoring directly.
This is done for cases when someone knows your secret,
he will not be able to remove all servers from monitoring.

A server can be removed from monitoring by sending a json response to the API,
when it will make a request to specified webhook url, in the following format:

```json
{"remove":true}
```
