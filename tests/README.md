# Tests

`composer test` runs the PHPUnit suite. There is no WordPress to load: the
core functions the library touches are stubbed in `bootstrap.php`, copied
from core where the answer depends on core's exact behaviour (`is_email()`,
`wp_is_mobile()`, the query-string helpers).

One folder per part of the library:

- **IP** -- `IP::get()` and every spoofing case, `ClientIp`, `TrustedProxies`,
  `Range`, canonical spellings, patterns, and `PublicTest` (`is_public()`,
  `anonymized_cidr()`, `hash()`).
- **Host** -- ports, typed domains, label boundaries, loopback, and the
  domains and endings an address is at.
- **Cloudflare** -- each header believed only from Cloudflare's addresses.
- **Country** -- the source chain, its filters, each CDN's header, the named
  `Header` source and the connection gate.
- **UserAgent** -- browsers, devices, bots, client hints, and the
  `agents.json` table shared with the JavaScript package.
- **Referrer** -- channels, sources, tracking parameters, icons, first-touch
  attribution, and the `sources.json` table shared with the JavaScript
  package.
- **Email** -- parsing, detection and matching.
- **Visit**, **Blocklist**, **Server** -- the record a log keeps, a
  blocklist's rules, and how `$_SERVER` is read.
- **MovingTest** -- the static call that replaces each of the old libraries'
  global helpers.

The shared tables (`fixtures/*.json`) are written by the JavaScript packages
and read here, so the same input gets the same answer on both sides.
