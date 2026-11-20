# Network

Who a WordPress request is from: the visitor's real IP, their country, their
language, their browser or bot, where they came from, and the email address
they gave -- and what the site runs on: the web server, PHP's limits, the disk
and whether this is the live site.

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
* Serve the visitor's language from `Accept-Language`, or the one they picked
* Know the web server, PHP's limits, the disk and whether this is the live site before an upload, an import or a payment
* Build a client for a keyed third-party API with a cache, a failure memory and one way of reading answers

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

`IP::get()` is the older call: it trusts Cloudflare and a local proxy at
once, and returns null for a private address unless `IP::get( true )`. It reads
`CF-Connecting-IP` only from Cloudflare's own addresses, then
`X-Forwarded-For`, then `X-Real-IP` and `Client-IP`. Its list can be set
site-wide with `define( 'SUGARCOMMERCE_TRUSTED_PROXIES', '203.0.113.5,198.51.100.0/24' )`
or the `sugarcommerce_trusted_proxies` filter, and `IP::trust()` hands that
list to anything else that should believe what it believes.

## Addresses and ranges

```php
IP::canonical( '2001:DB8:0::1' );            // "2001:db8::1" -- one spelling to store
Range::normalize( '203.0.113.77/24' );       // "203.0.113.0/24"
Range::normalize( '203.0.113.*' );           // "203.0.113.0/24"
Range::normalize( '2001:db8:*' );            // "2001:db8::/32" -- groups left off the end are stars
Range::normalize( '192.168.*' );             // "192.168.0.0/16" -- so are parts left off an IPv4 one
Range::normalize( '2001:db8::*' );           // "2001:db8::/112" -- "::" is noughts, as in an address
Range::contains_any( $rules, $ip );          // IPv4 and IPv6, any of the three forms
Range::prefix( $typed ) >= 8;                // refuse a rule that blocks a continent
IP::sanitize_pattern_list( $_POST['ips'] );  // one a line, or comma-separated
IP::is_match( $ip, $rules );                 // Range::contains_any() by its older name
```

`Range` is the library's one matcher: a prefix (`/24`), stars (`203.*.113.9`,
`2001:db8:*`) or from-to (`203.0.113.1-203.0.113.50`), in either family, an
address compared as an address whatever its spelling. `IP::is_match()`,
`is_in_range()`, `matches_wildcard()`, `is_valid_pattern()`,
`is_valid_range()` and `is_valid_wildcard()` keep their names and read with
it, so what a list matches and what its sanitiser keeps always agree.

`IP::is_private()` is PHP's reading. `IP::is_public()` is stricter: it refuses
carrier-grade NAT, the documentation blocks and multicast, and sees through
an IPv6 address that carries an IPv4 one -- `64:ff9b::7f00:1` is 127.0.0.1 on
any network with a NAT64 gateway. Use it for "public addresses only", such as
a webhook URL.

## Keeping an address without keeping the person

```php
IP::anonymize( '203.0.113.42' );             // "203.0.113.0"
IP::anonymize( '2001:db8:85a3:8d3:1319:8a2e:370:7348' ); // "2001:db8:85a3:8d3::" -- the /64, as core keeps
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
Email::clean( 'Jordan@EXAMPLE.COM' );                // "Jordan@example.com" -- the name's case is kept
```

`parse()` agrees with WordPress's `is_email()`, so an address already stored
reads back. At the door, where an address is about to be accepted, ask the
stricter question -- no dot at either end of the name or two in a row, a
top-level domain of letters, the RFC 5321 lengths:

```php
Email::is_valid( 'pat.@example.com' );               // false; is_email() says yes
```

`mask()` splits at the last `@` and hides how long the name is. For an admin
list that should look like what it hides, and show whatever is stored when
it is not an address:

```php
Email::mask( $email, keep: 2, show_length: true, keep_invalid: true );   // "ge••••@gmail.com", "nope"
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

## Languages

```php
use SugarCommerce\Network\AcceptLanguage;

AcceptLanguage::get_best_match( [ 'en', 'de', 'fr-CA' ], 'en' );   // de-AT is served by "de"
AcceptLanguage::pick( [ $_GET['lang'] ?? null, $_COOKIE['lang'] ?? null ], [ 'en', 'de' ], 'en' );
AcceptLanguage::is_rtl();                       // the visitor's first language reads right to left
AcceptLanguage::is_rtl_language( 'az-Arab' );   // true -- the script decides
AcceptLanguage::normalize( 'zh_hant_tw' );      // "zh-Hant-TW"
AcceptLanguage::endonym( 'pt-BR' );             // "Português (Brasil)", for a language picker
AcceptLanguage::get_primary( $saved_server );   // any reader, from a saved request
```

The header is the visitor's to write, so `q=0` is a refusal, a quality is
held to 0-1, what is not a language tag is dropped, and only the first
`AcceptLanguage::MAX_ENTRIES` (50) entries of the first
`AcceptLanguage::MAX_LENGTH` (1,000) bytes are read. An exact tag anywhere
in the header beats a base-language match.

`pick()` is for a site with a language picker: the visitor's own choice
beats the browser, and an array from `?lang[]=`, garbage or a language not
on offer is skipped. `get_common_languages()` and `get_base_languages()`
give a select's options (`$as_options` for `value`/`label` pairs); the list
is the same as conditions' `Options\Request::get_languages()`, and the two
should stay in step.

## The server and the site

```php
use SugarCommerce\Network\Disk;
use SugarCommerce\Network\Environment;
use SugarCommerce\Network\Php;
use SugarCommerce\Network\Server;

Server::software();                 // "nginx/1.27.0"
Server::is_nginx();                 // Flywheel, which Local runs, counts
Server::supports_htaccess();        // Apache with mod_rewrite seen
Server::has_xsendfile();            // Apache with mod_xsendfile, LiteSpeed; nginx when the filter says so
Server::port();                     // 1-65535, else 443 or 80

Php::has_memory( '256M' );          // still free under the limit
Php::missing_extensions( [ 'intl', 'gd' ] );
Php::has_function( 'set_time_limit' ); // not switched off by the host

Disk::space();                      // [ 'total', 'free', 'used', 'percent' ] for the site's disk
Disk::has_space( '1G', $dir );

Environment::type();                // "local", "development", "staging", "production"
Environment::is_production();       // exactly one of the four is true
```

Every `Server` method takes the same optional `$server` as the rest of the
library. On nginx, `X-Accel-Redirect` needs an `internal` location PHP cannot
see, so say when it is set up:

```php
add_filter( 'sugarcommerce_nginx_xsendfile', '__return_true' );
```

`Environment` asks WordPress first: `WP_ENVIRONMENT_TYPE` is the site owner
saying what the site is, and is believed over any guess -- production
included. Only a site that never said is guessed at: a local address
(loopback, a private address, `*.test`, `*.local`, `*.localhost`,
`*.example`, `*.invalid` -- not `*.dev`, which is a real public ending), then
a `staging`, `stage`, `test` or `demo` label, then `WP_ENV`, `ENVIRONMENT`
or `APP_ENV`, and `WP_DEBUG` last. `Environment::is_local_host( $host )` is
the address test on its own.

Whether a request came through Cloudflare is `( new Cloudflare() )->is_active()`,
which asks who connected rather than trusting a header anyone can send.

The largest upload WordPress will take is core's `wp_max_upload_size()`, and
its folder for temporary files `get_temp_dir()`: both honour the site's own
settings (`upload_size_limit`, `WP_TEMP_DIR`), so the library has no copy.

## Asking a third-party API

`Remote\Service` is the base for a client of a keyed API -- an IP reputation
service, a geocoder. It is the one copy `sugarcommerce/ip-lookup` and
`sugarcommerce/geocoding` build their providers on: the key, a transient
cache in front of every question, a short memory of what just failed (so an
API that is down or out of quota costs one visitor a timeout, not every
visitor), and reading the HTTP API's answer.

```php
use SugarCommerce\Network\Remote\Response;
use SugarCommerce\Network\Remote\Service;

final class Acme extends Service {
    protected const ID          = 'acme';      // cache keys and error codes: acme_http_error
    protected const NAME        = 'Acme';      // error messages
    protected const CACHE_GROUP = 'lookups';   // keys start lookups_acme_; default "remote"
    protected const CACHE_TTL   = DAY_IN_SECONDS;

    public function check( string $ip ): AcmeAnswer|WP_Error {
        if ( ! self::is_lookupable( $ip ) ) {  // public internet only: anything else costs a request for nothing
            return $this->invalid_ip( $ip );
        }

        $data = $this->remember( $ip, fn() => $this->json( $this->request( 'GET', 'https://api.acme.test/' . $ip ) ) );

        return is_wp_error( $data ) ? $data : new AcmeAnswer( $data );
    }
}

final class AcmeAnswer extends Response {}    // get(), to_array(), $answer->field

$acme = new Acme( $key, [ 'cache_ttl' => HOUR_IN_SECONDS, 'failure_ttl' => 60, 'cache' => true ] );
$acme->clear_cache( '8.8.8.8' );   // one answer
$acme->clear_cache();              // every answer this service kept
```

Answers are keyed by the question and the API key, so two accounts never
share one. Clearing everything starts a new generation of keys, so it works
with a persistent object cache too, where the transients are not in the
options table to be found by name.

`Referrer\Icons` finds its pictures' URL with `SugarCommerce\Plugin\Assets`,
the family's one asset resolver.

## Layout

Each class a consumer calls is at the top of `src/`. Folders hold what belongs
to one of them: `Country/` (the sources a country is read from), `Email/` (the
address's parts and the lists it is checked against), `Referrer/` (the source
tables, first-touch attribution and icons), `Remote/` (the base for a
third-party API client) and `UserAgent/` (the agent tables). A class with more
than one job keeps one trait per job in `src/Traits/<Class>/`: `IP`,
`Referrer`, `UserAgent` and `AcceptLanguage`.

## Security

* A forwarding header is believed only from a proxy you named, read from the right, so a visitor cannot pick their own IP.
* `CF-Connecting-IP` is believed only from Cloudflare's own addresses; a proxy of your own passes on whatever the visitor sent.
* `IP::is_public()` refuses private, loopback, link-local, carrier NAT, documentation, multicast and reserved blocks in both families, and an IPv6 address carrying an IPv4 one (mapped, SIIT, NAT64, 6to4) by the address it carries -- the check webhook and API URLs are held to.
* A country header counts only from the CDN's addresses when you name them, and only shaped as a country.
* A referrer that is not http(s), not UTF-8 or over 2,048 characters is no referrer; one kept is percent-encoded so it cannot break out of a link.
* UTM tags are cut to letters, digits and a little punctuation, 60 characters, and a click ID's value is never kept.
* A first-touch cookie is size-capped, checked as JSON before it is decoded, cleaned field by field on every read, and never readable by script.
* A user agent is capped at 512 characters and `Accept-Language` at 1,000 bytes and 50 entries before any pattern runs.
* A domain longer than DNS allows is no domain, so taking an address's domain apart costs nothing however long it is.
* A blocked domain matches with or without a trailing dot.
* An address is hashed with a key, never a plain hash, and the key is refused when empty.
* An API key is part of every cache key, so two accounts never share an answer.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* `sugarcommerce/plugin`, for the source icons' URL

## License

GPL-2.0-or-later
