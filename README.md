# Network

Who a WordPress request is from: the visitor's real IP, their country, their
browser or bot, where they came from, and the email address they gave.

## What it does

Every one of those arrives in something the visitor controls. A forwarding
header is anyone's to send, so reading `X-Forwarded-For` without asking who
connected lets a visitor pick their own IP -- and with it every rate limit,
ban and fraud score keyed on one. `CF-IPCountry` sent straight to the origin
picks a country. A referrer can be a `javascript:` URL, a user agent can be
2,000 characters of padding, and `dave+shop@gmail.com` is the same customer
as `d.a.v.e@gmail.com`.

This reads each of them the careful way, once, so the add form, the checkout
and the log all get the same answer -- and keeps what a log needs without
keeping the person.

## Features

* Get the visitor's real IP, believing a forwarding header only from a proxy you named
* Match an address against ranges however they were typed: `/24`, `203.0.113.*`, `a-b`
* Get the visitor's country from Cloudflare, CloudFront, Fastly, BunnyCDN or a GeoIP module, refusing a spoofed header
* Tell a phone from a tablet, a crawler from an AI fetching a page for a person, a link preview from a visit
* File a visit under search, social, email, AI, paid, campaign, referral or direct -- even when the app sent no referrer
* Remember a visitor's first touch until they buy, without keeping anything about the person
* Ask the questions an email address raises: same person, role mailbox, typo, disposable `+tag`
* Keep a visit for a log as keyed hashes and a network, and block by IP, email or domain

## Installation

```bash
composer require sugarcommerce/network
```

## Quick start

At checkout, note who placed the order without keeping the address itself:

```php
use SugarCommerce\Network\ClientIp;
use SugarCommerce\Network\Country;
use SugarCommerce\Network\IP;
use SugarCommerce\Network\Referrer;
use SugarCommerce\Network\Request;
use SugarCommerce\Network\TrustedProxies;

$ip     = ClientIp::resolve( null, TrustedProxies::cloudflare() );   // behind Cloudflare
$source = Referrer::from_request();

update_post_meta( $order_id, '_network', IP::anonymized_cidr( (string) $ip ) );  // "203.0.113.0/24"
update_post_meta( $order_id, '_country', Country::resolve() );                    // "GB", or ""
update_post_meta( $order_id, '_device', Request::describe() );                    // "Chrome 131 on macOS"
update_post_meta( $order_id, '_source', $source['label'] );                       // "Google search", "TikTok"
```

## The visitor's IP

`REMOTE_ADDR` is the proxy's address when there is a proxy, and a forwarding
header is attacker-controlled unless you know which proxy wrote it. Say what
the site is behind, and the header is read only when the connection came from
that, from the right-hand end, stepping over your own proxies:

```php
ClientIp::resolve();                                          // reached directly
ClientIp::resolve( null, TrustedProxies::cloudflare() );
ClientIp::resolve( null, TrustedProxies::local() );           // nginx or Caddy on this host
ClientIp::resolve( null, TrustedProxies::none()->and_also( [ '198.51.100.7' ] ) );
```

`IP::get()` is the older call, kept as it was: it trusts Cloudflare and a
local proxy at once, and returns null for a private address unless
`IP::get( true )`. Its list can be set site-wide with
`define( 'SUGARCOMMERCE_TRUSTED_PROXIES', '203.0.113.5,198.51.100.0/24' )` or
the `sugarcommerce_trusted_proxies` filter, and `IP::trust()` hands that list
to anything else that should believe what it believes.

## Addresses and ranges

```php
IP::canonical( '2001:DB8:0::1' );            // "2001:db8::1" -- one spelling to store
Range::normalize( '203.0.113.77/24' );       // "203.0.113.0/24"
Range::normalize( '203.0.113.*' );           // "203.0.113.0/24"
Range::contains_any( $rules, $ip );          // IPv4 and IPv6, any of the three forms
Range::prefix( $typed ) >= 8;                // refuse a rule that blocks a continent
IP::sanitize_pattern_list( $_POST['ips'] );  // one a line, or comma-separated
```

`IP::is_private()` is PHP's reading. `IP::is_public()` is stricter: it refuses
carrier-grade NAT, the documentation blocks and multicast, and sees through
an IPv6 address that carries an IPv4 one -- `64:ff9b::7f00:1` is 127.0.0.1 on
any network with a NAT64 gateway. Use it for "public addresses only", such as
a webhook URL.

## Keeping an address without keeping the person

```php
IP::anonymize( '203.0.113.42' );             // "203.0.113.0"
IP::anonymized_cidr( '2001:db8:85a3::1' );   // "2001:db8:85a3::/48" -- plainly a network
IP::hash( $ip, wp_salt( 'auth' ) );          // HMAC-SHA256: count visitors, store none
```

A visit for a download log or an audit trail, all at once -- two keyed hashes
to count with, and a country, device and network a person may see:

```php
use SugarCommerce\Network\Visit;

$visit = Visit::capture( Visit::hmac( wp_salt( 'auth' ) ), TrustedProxies::cloudflare(), Visit::CLOUDFLARE_COUNTRY );

$wpdb->insert( $table, [ 'ip_hash' => $visit->ip_hash, 'ua_hash' => $visit->agent_hash ] + $visit->summary() );
// summary(): [ 'country' => 'IE', 'device' => 'Chrome 131 on macOS', 'network' => '203.0.113.0/24' ]
```

## Host names

```php
use SugarCommerce\Network\Host;

Host::normalize( 'https://Shop.Example.com/page' );   // "shop.example.com"; "" for an IP or one label
Host::is_within( 'a.example.com', 'example.com' );     // true; "evil-example.com" is not
Host::is_loopback( 'app.localhost:3000' );             // true; "localhost.evil.example" is not
Host::without_port( '[::1]:8080' );                    // "::1"
Host::parents( 'pat@mail.shady.example' );             // [ "mail.shady.example", "shady.example" ]
Host::suffixes( 'example.co.uk' );                     // [ "uk", "co.uk" ]
```

`is_within()` refuses a host a browser would not go to, such as
`evil.com\.example.com`, the classic way past a redirect allowlist.

## Cloudflare

```php
use SugarCommerce\Network\Cloudflare;

$cloudflare = new Cloudflare();      // believes Cloudflare's own addresses, nothing else

$cloudflare->is_tor();               // CF-IPCountry: T1
$cloudflare->ray_id();               // "8a1b2c3d4e5f6789-LHR", for a support ticket
$cloudflare->tls_version();          // "https", from CF-Visitor
```

Each returns nothing when the request did not come through Cloudflare, so a
visitor reaching the origin directly cannot set them.

## Country

```php
$country = Country::resolve();       // "GB", or "" when nothing answered

$result = Country::resolve_detailed();
$result->get_source();               // "cloudflare"
$result->get_confidence();           // "high"
```

The CDN headers are tried in order, each refused unless it is two letters
(`XX` and `T1` are not countries) and the CDN's signature header came with it.
Name the CDN's addresses to believe it only from them, and add your own
source:

```php
use SugarCommerce\Network\Country\Sources\Cloudflare;
use SugarCommerce\Network\Country\Sources\Header;

Country::set_sources( [ new Cloudflare( TrustedProxies::cloudflare() ) ] );
Country::prepend_source( new Header( 'X-Geo-Country', 'balancer', 'high', TrustedProxies::local() ) );
```

`visitor_country_sources` and `visitor_country_result` filter the chain and
the answer; `Country::use_prefix( 'myplugin' )` renames them
`myplugin_country_*`, so a bundled copy has filters of its own.

## Browsers, devices and bots

```php
Request::device_type();        // "mobile", "tablet", "desktop", "bot" -- a client hint wins
Request::is_ai_bot();          // GPTBot, ClaudeBot, PerplexityBot...
Request::is_ai_user_agent();   // ChatGPT-User: a person asked to see this page
Request::is_link_preview();    // WhatsApp drawing a card: not a visit, not a download
Request::in_app_browser();     // "TikTok", "Instagram"

UserAgent::parse( $agent );    // everything at once, from a string you pass
UserAgent::clean( $agent );    // trimmed, capped at 512, null for nothing -- ready to store
ClientHints::windows_version(); // "Windows 11", which no user agent can say
```

The rules are the ones the JavaScript package `@flarecart/user-agent` uses,
so a string gets the same answer on both sides. Fine for analytics, never a
security boundary: user agents lie.

## Where they came from

```php
$source = Referrer::from_request();
// [ 'channel' => 'paid', 'source' => 'TikTok Ads', 'label' => 'TikTok Ads',
//   'click_id' => [ 'param' => 'ttclid', 'network' => 'TikTok Ads' ], 'internal' => false, ... ]
```

UTM tags beat a click ID, which beats the in-app browser, which beats the
referrer. The in-app browser is read from the request's agent, so a link
tapped in TikTok -- which sends no referrer -- is TikTok and not "direct".
`internal` is a referrer from the site itself or a payment page: keep the
source the visit already had.

```php
use SugarCommerce\Network\Referrer\Icons;
use SugarCommerce\Network\Referrer\Tracking;

Tracking::strip( $url );            // utm_*, fbclid, gclid off; WordPress's own "s" left alone
Referrer::safe_url( $referrer );    // http(s), valid UTF-8, nothing that breaks out of an href
Icons::url( $source['source'] );    // a brand mark for a report, or a plain picture
```

The tables are the JavaScript package `@flarecart/referrer`'s, so a visit is
filed the same way whichever side saw it.

## First-touch attribution

The visit that said where somebody came from is gone by the time they buy.
`FirstTouch` notes it in a cookie, and the cart or the order copies it:

```php
use SugarCommerce\Network\Referrer\FirstTouch;

$touch = new FirstTouch( [ 'prefix' => 'myplugin', 'in_app' => [ Request::class, 'in_app_browser' ] ] );
$touch->init();                                      // on template_redirect

$source = $touch->decode( $touch->stored() );        // [ 'source' => 'Google', 'medium' => 'search', ... ]
$meta   = $touch->to_metadata( $source, 'myplugin_' ); // onto a Stripe Checkout Session
```

Nothing about the person is kept: the referring host, the tags, the path, a
click ID by its name and never its value. Everything read back goes through
`clean()`.

## Email addresses

```php
use SugarCommerce\Network\Email;

$email = Email::parse( $_POST['email'] );            // null when not valid

$email->has_typo() && $email->suggested_email();     // "pat@gmial.com" -> "pat@gmail.com"
$email->equals_base( $existing );                    // the same customer with a +tag
$email->is_role_based();                             // info@, sales@
Email::mask( 'patricia@example.com' );               // "p••@example.com"
```

## Blocklists

The rules without the storage, so the form that adds an entry and the check
that enforces it cannot drift apart:

```php
use SugarCommerce\Network\Blocklist;

$rules = new Blocklist( fn( string $ip ): string => IP::hash( $ip, wp_salt( 'auth' ) ) );

$rules->pattern( Blocklist::DOMAIN, ' @Spam.Example ' );   // "@spam.example"; "" when not valid

foreach ( $rules->keys( IP::get(), $email ) as $type => $pattern ) {   // ip, then email, then domain
	// SELECT reason FROM {$table} WHERE type = %s AND pattern = %s
}
```

## Moving from arraypress/wp-*

This library is `wp-ip-utils`, `wp-user-agent-utils`, `wp-referrer-utils`,
`wp-email-utils` and `wp-visitor-country` in one. Every class keeps its name
and its methods, under `SugarCommerce\Network`:

| Was | Now |
|---|---|
| `ArrayPress\IPUtils\{IP, IPs, Range, ClientIp, TrustedProxies}` | `SugarCommerce\Network\{...}` |
| `ArrayPress\UserAgentUtils\{UserAgent, ClientHints, Request}` | `SugarCommerce\Network\{...}` |
| `ArrayPress\ReferrerUtils\Referrer` | `SugarCommerce\Network\Referrer` |
| `ArrayPress\ReferrerUtils\{Sources, Campaigns, Tracking, Icons, FirstTouch}` | `SugarCommerce\Network\Referrer\{...}` |
| `ArrayPress\EmailUtils\Email` | `SugarCommerce\Network\Email` |
| `ArrayPress\EmailUtils\Data\*` | `SugarCommerce\Network\Email\Data\*` |
| `ArrayPress\EmailUtils\Traits\*` | `SugarCommerce\Network\Email\*` |
| `ArrayPress\VisitorCountry\Country` | `SugarCommerce\Network\Country` |
| `ArrayPress\VisitorCountry\{Result, Source}` | `SugarCommerce\Network\Country\{Result, Source}` |
| `ArrayPress\VisitorCountry\Sources\*` | `SugarCommerce\Network\Country\Sources\*` |

What moved, because two libraries each had one:

| Was | Now |
|---|---|
| `IP::strip_port( $value )` | `Host::without_port( $value )` |
| `IP::is_tor()` | `( new Cloudflare( IP::trust() ) )->is_tor()` -- or `( new Cloudflare() )` to believe Cloudflare's addresses only |
| `IP::get_ray_id()` | `( new Cloudflare( IP::trust() ) )->ray_id()` -- null now unless shaped like a ray ID |
| `Email::host_from( $value )` | `Host::from( $value )` |
| `Email::domain_parents( $value )` | `Host::parents( $value )` |
| `Email::domain_suffixes( $value )` | `Host::suffixes( $value )` |
| `Email::is_hostname( $name )` | `Host::is_hostname( $name )` |
| `Referrer::is_domain_or_subdomain( $host, $domain )` | `Host::is_within( $host, $domain )` -- also case-blind, and refuses a host no browser would go to |

The global helpers are gone. A global function is the one thing Strauss
cannot prefix, so two plugins bundling different copies shared whichever
loaded first:

| Was | Now |
|---|---|
| `get_user_ip()` | `IP::get()` |
| `anonymize_ip( $ip )` | `IP::anonymize( $ip )` |
| `sanitize_ip_list( $input )` | `IP::sanitize_pattern_list( $input, true )` |
| `get_user_agent()` | `Request::agent()` |
| `get_referrer_url()` | `Referrer::get() ?? ''` |
| `get_referrer_source( $url )` | `Referrer::get_source( $url )` |
| `get_referrer_utm_params( $url )` | `Referrer::get_utm_parameters( $url )` |
| `is_referrer_from_search( $url )` | `Referrer::is_search_engine( $url )` |
| `get_referrer_search_terms( $url )` | `Referrer::get_search_terms( $url ) ?? ''` |
| `parse_email( $email )` | `Email::parse( $email )` |
| `sanitize_email_list( $input )` | `Email::sanitize_pattern_list( $input, true )` |

Names in the site's configuration, and the text domain:

| Was | Now |
|---|---|
| `ARRAYPRESS_TRUSTED_PROXIES` | `SUGARCOMMERCE_TRUSTED_PROXIES` |
| `arraypress_trusted_proxies` filter | `sugarcommerce_trusted_proxies` |
| `arraypress_referrer_{search_engine,social_platform,ai_assistant,email_provider,traffic_source,utm_parameter}_options` | `sugarcommerce_referrer_..._options` |
| `visitor_country_sources`, `visitor_country_result` | unchanged; `Country::use_prefix()` renames them |
| text domain `arraypress` | `sugarcommerce` |

Answers that changed:

* `Referrer::from_request()` with no argument reads the in-app browser from the request's agent. Pass `''` for none, as before.
* `IP::sanitize_pattern_list()` splits text on commas as well as new lines.
* `Referrer::safe_url()` refuses a referrer that is not valid UTF-8.
* `UserAgent::current()`, `Request::agent()` and `ClientHints` treat an array where a header should be as nothing, not "Array"; `UserAgent::current()` reads the live request unslashed.
* `ClientHints::brands()` returns at most `ClientHints::MAX_BRANDS` (8).

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
