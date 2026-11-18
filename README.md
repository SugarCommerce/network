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

Php::max_upload_size();             // bytes: the smaller of upload_max_filesize and post_max_size, 0 for no limit
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

## Moving from arraypress/wp-*

This library is `wp-ip-utils`, `wp-user-agent-utils`, `wp-referrer-utils`,
`wp-email-utils`, `wp-visitor-country`, `wp-accept-language-utils` and
`wp-server-utils` in one, with `arraypress/email-validator`'s check in `Email`. Every class keeps its name
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
| `ArrayPress\AcceptLanguageUtils\AcceptLanguage` | `SugarCommerce\Network\AcceptLanguage` -- every method the same name |

`wp-server-utils`, renamed to read like the rest of the library:

| Was | Now |
|---|---|
| `ArrayPress\ServerUtils\Server::get_software()` | `SugarCommerce\Network\Server::software()` |
| `Server::get_ip()`, `get_port()` | `Server::address()`, `Server::port()` |
| `Server::is_apache()`, `is_nginx()`, `is_litespeed()`, `is_iis()`, `supports_htaccess()`, `has_mod_rewrite()`, `supports_url_rewriting()`, `supports_gzip()`, `has_xsendfile()`, `has_apache_module()` | `Server::` the same |
| `Server::get_apache_modules()` | `Server::apache_modules()` |
| `Server::is_cloudflare()` | `( new Cloudflare( IP::trust() ) )->is_active()` -- believed only from Cloudflare's addresses |
| `ArrayPress\ServerUtils\PHP::get_version()`, `meets_version_requirement()` | `SugarCommerce\Network\Php::version()`, `Php::meets_version()` |
| `PHP::get_memory_limit_bytes()`, `get_max_execution_time()`, `get_upload_max_filesize_bytes()`, `get_post_max_size_bytes()`, `get_max_input_vars()` | `Php::memory_limit()`, `max_execution_time()`, `upload_max_filesize()`, `post_max_size()`, `max_input_vars()` |
| `PHP::has_extension()`, `get_missing_extensions()`, `has_function()`, `are_uploads_enabled()`, `is_url_fopen_enabled()`, `has_sufficient_memory()` | `Php::has_extension()`, `missing_extensions()`, `has_function()`, `uploads_enabled()`, `url_fopen_enabled()`, `has_memory()` |
| `ArrayPress\ServerUtils\System::get_disk_space()`, `has_sufficient_disk_space()`, `get_temp_dir()`, `is_windows()`, `is_linux()`, `is_macos()` | `SugarCommerce\Network\Disk::space()`, `has_space()`, `temp_dir()`, `is_windows()`, `is_linux()`, `is_macos()` |
| `ArrayPress\ServerUtils\Environment::get_type()`, `is_localhost()`, `is_staging()`, `is_development()`, `is_production()` | `SugarCommerce\Network\Environment::type()`, `is_local()`, `is_staging()`, `is_development()`, `is_production()` |
| `Environment::TYPE_LOCALHOST`, `TYPE_STAGING`, `TYPE_PRODUCTION`, `TYPE_DEVELOPMENT` | `Environment::LOCAL`, `STAGING`, `PRODUCTION`, `DEVELOPMENT` |

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
| `is_valid_email( $email )` (arraypress/email-validator) | `Email::is_valid( $email )` |
| `sanitize_email( $email )` (arraypress/email-validator) | WordPress's own `sanitize_email()` -- the old one stood in for it only where core was absent. Lower-cased and checked: `Email::sanitize_email( $email ) ?? ''` |
| `get_accept_language()` | `AcceptLanguage::get_primary()` |
| `get_preferred_language( $available, $fallback )` | `AcceptLanguage::get_best_match( $available, $fallback )` |
| `accepts_language( $lang, $exact )` | `AcceptLanguage::accepts( $lang, $exact )` |
| `is_rtl_language()` | `AcceptLanguage::is_rtl()` |

Names in the site's configuration, and the text domain:

| Was | Now |
|---|---|
| `ARRAYPRESS_TRUSTED_PROXIES` | `SUGARCOMMERCE_TRUSTED_PROXIES` |
| `arraypress_trusted_proxies` filter | `sugarcommerce_trusted_proxies` |
| `arraypress_referrer_{search_engine,social_platform,ai_assistant,email_provider,traffic_source,utm_parameter}_options` | `sugarcommerce_referrer_..._options` |
| `visitor_country_sources`, `visitor_country_result` | unchanged; `Country::use_prefix()` renames them |
| `server_nginx_xsendfile` filter | `sugarcommerce_nginx_xsendfile` |
| text domain `arraypress` | `sugarcommerce` |

Answers that changed:

* `Referrer::from_request()` with no argument reads the in-app browser from the request's agent. Pass `''` for none, as before.
* `IP::sanitize_pattern_list()` splits text on commas as well as new lines.
* `Referrer::safe_url()` refuses a referrer that is not valid UTF-8.
* `UserAgent::current()`, `Request::agent()` and `ClientHints` treat an array where a header should be as nothing, not "Array"; `UserAgent::current()` reads the live request unslashed.
* `ClientHints::brands()` returns at most `ClientHints::MAX_BRANDS` (8).
* `AcceptLanguage::normalize()` follows BCP 47's casing: `zh-Hant-TW`, not `zh-HANT-TW`; `en-US-posix`, not `en-US-POSIX`. `extract_region()` skips a script, so `zh-Hant-TW` is `TW`, not `HANT`, and `zh-Hant` has none.
* `AcceptLanguage` reads only the first 50 entries of the first 1,000 bytes of the header.
* `AcceptLanguage::is_rtl()` also reads a script: `az-Arab` is right to left, `ku-Latn` is not.
* `Environment` asks WordPress first: a site that set `WP_ENVIRONMENT_TYPE` is that type whatever its address says, and `WP_DEBUG` on a declared production site no longer makes it development. Its local type is `"local"` (WordPress's word), not `"localhost"`.
* `Environment` no longer treats `*.dev` as local -- it is a real public ending. A private or reserved address, `*.localhost`, `*.example` and `*.invalid` are local.
* Exactly one of `is_local()`, `is_staging()`, `is_development()` and `is_production()` is true: a `staging.mysite.test` site is local and not also staging.
* `Server::software()` reads the request each time; there is no static cache. `Server::port()` is 1-65535, else 443 or 80 by HTTPS; it used to cast whatever was there.
* `Server::has_mod_rewrite()` also reads `REDIRECT_HTTP_MOD_REWRITE`, and the variable from the request as well as the environment.
* `Disk::space()` on a disk reporting no size is 0% full rather than a division by zero. The operating system is read from `PHP_OS_FAMILY`.
* `Php::max_upload_size()` is new: the number an upload form needs.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
