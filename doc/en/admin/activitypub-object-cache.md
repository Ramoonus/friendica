# Buffering bursts of public ActivityPub object reads

This optional experiment lets a shared HTTP cache answer repeated anonymous
`GET /objects/{guid}` requests before they enter PHP. The generated ActivityPub
data already has an application cache, but reading that cache still occupies a
PHP worker. HTTP caching can avoid that work for eligible reads.

## Scope and freshness

`system.activitypub_object_cache_ttl` defaults to `0` (disabled). With a positive
value, capped at 60 seconds, a successfully generated, public, non-deleted object
can carry `Cache-Control: public, max-age=0, s-maxage=<ttl>, must-revalidate`.
Browsers must revalidate; shared caches can reuse the response during its short
freshness window. Start with 30 seconds in a staging deployment.

Only parent posts and comments at the base object URL are eligible. Private and
unlisted objects, deleted objects, errors, activity representations, replies
collections, query strings, writes, authenticated requests, cookies, and HTTP
signatures are excluded. An anonymous response that sets a cookie is excluded,
too. `Vary` separates ActivityPub from HTML and separates anonymous reads from
authorization, cookie and signature variants. The proxy must bypass these
sensitive requests even when an anonymous cache entry already exists.

An edit, deletion or visibility change can leave a formerly public copy available
until its freshness window expires. This is an explicit tradeoff: keep the TTL at
zero if that window is unacceptable. There is no cross-server purge mechanism.
Disable stale-on-error and stale-while-refresh behavior. On rollback, disable the
front cache and purge its dedicated cache directory before relying on the
disabled origin setting: existing fresh entries otherwise remain until expiry.

Signed requests still reach PHP. Their proportion in the actual workload limits
the possible improvement; ordinary access logs do not show whether a request was
signed. Do not assume a Mastodon user-agent implies an anonymous request. This
cache also does not address expensive API timeline rendering or inbound inbox
processing.

## Staging setup with an Apache front proxy

Use a separate reverse-proxy virtual host in front of the staging Friendica
origin. The origin must contain the matching Objects module/cache policy change;
do not enable a generic cache in front of an unmodified origin. Preserve the
normal host, HTTPS forwarding and application authentication configuration.

1. Load `mod_proxy`, `mod_proxy_http`, `mod_cache`, `mod_cache_disk` and
   `mod_setenvif` on the front proxy.
2. Create `/var/cache/apache2/friendica-objects`, writable only by the proxy's
   service user. Keep it outside the document root and limit disk usage with
   `htcacheclean` (for example, a 100 MB limit for this dedicated directory).
3. Include `mods/sample-activitypub-object-cache.conf` inside the staging
   virtual host. Its scope is only base object URLs; it does not enable caching
   for profiles, inboxes or API routes. Adapt the cache path for the host.
4. On the **staging origin**, set the opt-in TTL with the system PHP executable:

   ```sh
   php bin/console.php config system activitypub_object_cache_ttl 30
   ```

5. Validate the proxy configuration before reloading it. Verify that a second
   eligible read is a cache hit and does not reach the origin. Temporarily enable
   `CacheHeader On` on the front virtual host, or log `%{cache-status}e`, for that
   check. Use the same `Accept: application/activity+json` header for each read.
6. Warm the anonymous cache, then verify that requests with `Authorization`,
   `Cookie`, `Signature` and `Signature-Input` still reach the origin and return
   `private, no-store`. Verify private, deleted and unlisted items against a real
   staging database and confirm that edits/deletions appear after expiry.

The sample keeps `CacheQuickHandler Off` so ordinary access-control phases still
run. It respects origin cache directives and request bypasses, disables heuristic
freshness and stale responses, and limits cached objects to 256 KB. Do not add
`CacheStorePrivate On`, `CacheStoreNoStore On`, or strip authorization/cookie
headers to force cache hits.

Apache's cache lock does not collapse simultaneous cold misses. The sample keeps
it off to avoid serving stale data during refresh. A first wave of uncached or
signed requests can still fill the PHP pool. Measure this residual load before
raising concurrency, particularly on a host shared with other PHP applications.

See the primary references for [Apache mod_cache](https://httpd.apache.org/docs/2.4/en/mod/mod_cache.html)
and [htcacheclean](https://httpd.apache.org/docs/2.4/en/programs/htcacheclean.html).

## Reproducible isolated test

Run the policy regression tests through the normal PHPUnit setup:

```sh
vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Network/ActivityPubObjectCacheTest.php
```

The proxy experiment needs Python 3, PHP CLI, Apache 2.4 and the modules listed in
its script. It creates a separate Apache process and PHP development server on
ephemeral loopback ports, with temporary cache/configuration files. It never loads
a Friendica database or modifies production services. On Debian/Ubuntu it uses
`/usr/lib/apache2/modules`; set `APACHE_MODULE_DIR` for a different module path.
When run as root, Apache workers use `www-data`.

```sh
python3 tests/Performance/activitypub-object-cache.py
```

The synthetic PHP origin uses the real cache policy and adds 100 ms of rendering
work. The test checks a warm burst of 100 requests at concurrency 25, cached HEAD
and conditional requests, bypasses after warming, cookie-producing responses,
excluded routes, expiry after deletion/visibility changes and the disabled policy.
It checks the origin-render counter, not just response speed. This is an HTTP
cache experiment, not a full Friendica benchmark or a guarantee about live traffic.
