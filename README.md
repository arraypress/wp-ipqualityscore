# IPQualityScore

One API for the fraud checks a store keeps needing: the IP, the email, the
phone number and the URL.

## What it does

IPQualityScore answers several questions a checkout asks — is this a proxy,
is this inbox real, does this phone number exist, is this link malicious —
and this wraps all of them the same way. Each returns a response object with
named methods rather than a decoded array, and answers are cached.

Its strictness is a dial rather than a setting you get right first time, so
that is exposed too: turn it up for a store that sees a lot of fraud, down
for one where a false positive costs a real sale.

## Features

* Tell whether an IP is a proxy, VPN, Tor exit or bot
* Tell whether an email address is disposable, and whether it actually exists
* Validate a phone number and see which carrier and line type it is
* Scan a URL, or a file, for malware before it reaches somebody
* Tune how suspicious the service is, per call or for the whole client
* Cache answers, and keep an eye on the credits you have left

## Installation

```bash
composer require arraypress/wp-ipqualityscore
```

## Quick start

Score an order at checkout on the two signals that matter most:

```php
use ArrayPress\IPQualityScore\Client;

$client = new Client( $api_key );

$ip    = $client->check_ip( $order->ip );
$email = $client->validate_email( $order->email );

if ( is_wp_error( $ip ) || is_wp_error( $email ) ) {
	return; // Do not lose an order to somebody else's downtime.
}

if ( $ip->is_proxy() || $email->is_disposable() ) {
	$order->flag_for_review();
}
```

Strictness climbs from 0 to 3, and each step catches more fraud and more
innocent customers with it:

```php
$client->set_strictness( 1 );
```

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* An IPQualityScore API key

## License

GPL-2.0-or-later
