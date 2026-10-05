# Network

Request-level network utilities: spoof-resistant client IP behind proxies and CDNs, CIDR matching, GDPR anonymisation, user-agent and bot detection, referrer and campaign attribution. Zero dependencies.

## Why

`$_SERVER['HTTP_X_FORWARDED_FOR']` is a request header. Anyone can send it. Reading it without checking who the request came from means an attacker sets their own IP address — which defeats every rate limit, ban list, fraud rule and geo-restriction built on top of it.

The fix is not complicated, but it has to be done in the right order: trust the forwarding header **only** when the connecting address is a proxy you actually trust, and then take the right hop from the chain. Almost every hand-rolled implementation gets one of those two wrong.

## Features

- 🛡️ **Spoof-resistant client IP** — forwarding headers honoured only from trusted proxies.
- ☁️ **Cloudflare-aware** — the published edge ranges, plus country, ray id and Tor detection.
- 🎯 **CIDR matching** — IPv4 and IPv6, plus IPv4 wildcards and allowlist validation.
- 🕵️ **GDPR anonymisation** — last octet or last 80 bits zeroed, written as a CIDR if you like, or a keyed hash for a stable pseudonym.
- 🏷️ **Hosts** — ports off bracketed IPv6, typed domains cleaned, subdomain checks on a label boundary.
- 🤖 **Bot detection** — search crawlers, and the AI crawlers tracked by `ai.robots.txt`.
- 📱 **Device and browser** — with the client hints needed for the things a user agent no longer tells you.
- 📈 **Attribution** — referrer classification and UTM campaigns, sanitised for storage.

## Requirements

PHP 8.5+

## Installation

```bash
composer require sugarcommerce/network
```

## Client IP

```php
use SugarCommerce\Network\{ClientIp, TrustedProxies};

// Behind Cloudflare
$resolver = new ClientIp( TrustedProxies::cloudflare() );

// Behind your own load balancer
$resolver = new ClientIp( TrustedProxies::none()->and_also( '10.0.0.0/8' ) );

// Direct — no proxy, no forwarding headers honoured
$resolver = new ClientIp();

$ip = $resolver->resolve();   // ?string
```

The rule this enforces: a forwarding header is only read when `REMOTE_ADDR` is inside a trusted range. Directly-connected traffic is taken at face value, whatever headers it sends.

```php
TrustedProxies::cloudflare( [ '10.0.0.0/8' ] );   // Cloudflare plus your own
TrustedProxies::local();                          // RFC 1918 — development
TrustedProxies::none()->using_headers( 'X-Real-IP' );
```

**Set this to match your actual deployment.** Trusting every private range while sitting behind a CDN that does not strip headers is how the guard gets bypassed; trusting nothing while behind a load balancer means every visitor shares one IP.

## Addresses

```php
use SugarCommerce\Network\{Cidr, Ip};

Cidr::contains( '10.0.0.5', '10.0.0.0/8' );   // CIDR
Cidr::contains_any( $ip, $ranges );
Cidr::matches( $ip, '192.168.1.*' );          // wildcard (IPv4 only)
Cidr::matches( $ip, '192.168.1.10' );         // exact address
Cidr::matches_any( $ip, $patterns );
Cidr::filter_valid( $operator_input );        // drop typos before storing

Ip::is_private( $ip );
Ip::version( $ip );                                  // 4 or 6
Ip::anonymize( '203.0.113.42' );                     // '203.0.113.0'
Ip::anonymize( '2001:db8::1' );                      // '2001:db8::'
Ip::hash( $ip, $secret );                            // stable pseudonym
Ip::anonymized_cidr( '203.0.113.42' );               // '203.0.113.0/24'
```

`anonymize()` is the GDPR-safe form to log — zeroing the last octet (or the last 80 bits) leaves you geography and rough network without an identifier.

`anonymized_cidr()` is the same masking written as the network it stands for, so a log reader cannot take `203.0.113.0` for somebody's address.

`hash()` is for when you need to count unique visitors without storing who they were. Keep the secret out of the database; with it, the mapping is reversible by brute force in seconds — the address space is small.

## Hosts

```php
use SugarCommerce\Network\Host;

Host::without_port( '[::1]:8080' );                 // '::1'
Host::without_port( 'shop.example.com:8443' );      // 'shop.example.com'
Host::normalize( 'https://Shop.Example.com/page' ); // 'shop.example.com'
Host::normalize( '203.0.113.5' );                   // '' — not a domain
Host::is_within( 'a.example.com', 'example.com' );  // true
Host::is_within( 'evil-example.com', 'example.com' ); // false
```

`without_port()` handles bracketed IPv6, where stripping `:\d+$` leaves the brackets on, and leaves a bare IPv6 address alone, where it would eat the last group. `normalize()` is for a domain somebody typed — they paste URLs — and refuses IP addresses, single labels and anything DNS would not hold. `is_within()` compares on a label boundary.

## User agents

```php
use SugarCommerce\Network\UserAgent;

$agent = UserAgent::current();

UserAgent::browser( $agent );        // 'Chrome'
UserAgent::browser_version( $agent );
UserAgent::os( $agent );             // 'macOS'
UserAgent::device_type( $agent );    // 'mobile' | 'tablet' | 'desktop'
UserAgent::describe( $agent );       // 'Chrome 131 on macOS'

UserAgent::is_bot( $agent );
UserAgent::is_ai_bot( $agent );      // GPTBot, ClaudeBot, PerplexityBot, …
UserAgent::bot_name( $agent );
```

Three things no user agent can tell you, whatever the library:

- **Windows 11 is undetectable.** It reports `Windows NT 10.0`, byte-identical to Windows 10 — the token was frozen deliberately. Any pattern matching a build number looks plausible and never fires.
- **macOS reports 10.15.7 forever.** Frozen at Catalina regardless of the actual version.
- **iPads mostly claim to be Macs.** "Request Desktop Website" has been the iPadOS default since 13.

For the first, use client hints:

```php
use SugarCommerce\Network\ClientHints;

ClientHints::windows_version();   // '11' — needs the Accept-CH header
ClientHints::is_mobile();
ClientHints::platform();
ClientHints::model();
```

Client hints only arrive if you ask for them. Send `ClientHints::ACCEPT_CH` as the `Accept-CH` response header, and the browser includes them on subsequent requests.

## Attribution

```php
use SugarCommerce\Network\Referrer;

$source = Referrer::analyse( $_SERVER['HTTP_REFERER'] ?? '', $_GET );

$source->channel;    // 'search' | 'social' | 'email' | 'paid' | 'campaign' | 'referral' | 'direct'
$source->name;       // 'Google', 'Twitter', a campaign name, a domain
$source->term;       // search term or utm_source
$source->label();    // 'Google search for "widgets"'
$source->to_array(); // the shape to persist against an order

Referrer::is_internal( $referrer, $site_url );
```

UTM parameters win over the header, since a tag is deliberate and a `Referer` may have been trimmed by the browser.

Everything here is attacker-controlled, so everything here is sanitised. Only `http` and `https` referrers survive — a stored `javascript:` URL rendered as a link is cross-site scripting one click away — and the characters that break out of an HTML attribute are encoded or dropped. See [SECURITY.md](SECURITY.md).

## Cloudflare

```php
use SugarCommerce\Network\Cloudflare;

$cf = new Cloudflare();

$cf->is_active();     // request came through the edge
$cf->country();       // 'IE' — from CF-IPCountry
$cf->ray_id();
$cf->is_tor();
$cf->tls_version();
```

Country here is free and instant, with no GeoIP database to ship or keep current — but it is only present when the request actually came through Cloudflare, so check `is_active()` first.

## Testing

```bash
composer install
composer test
```

183 tests, 337 assertions — including every header-spoofing path, ports on bracketed and bare IPv6 hosts, typed domains, label-boundary matching, IPv4-mapped and NAT64 addresses, the user-agent patterns verified against MDN and vendor documentation, and the referrer sanitisation cases.

## License

GPL-2.0-or-later
