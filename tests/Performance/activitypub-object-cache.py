#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2010-2026 the Friendica project
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Run an isolated Apache/PHP cache experiment, without a database or production config.

Requires Python 3, PHP CLI, Apache 2.4 and the modules used below. When run as
root, Apache workers use www-data. All listeners are on loopback ephemeral ports.
"""

import concurrent.futures
import os
import pathlib
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]


def port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


def wait_for_port(number, process):
    for _ in range(100):
        if process.poll() is not None:
            raise RuntimeError("Test server exited during startup")
        try:
            with socket.create_connection(("127.0.0.1", number), timeout=0.1):
                return
        except OSError:
            time.sleep(0.05)
    raise RuntimeError("Test server did not start")


def run():
    apache = shutil.which("apache2") or shutil.which("httpd")
    php = shutil.which("php")
    if not apache or not php:
        raise RuntimeError("Apache and PHP CLI are required")
    modules = pathlib.Path(os.environ.get("APACHE_MODULE_DIR", "/usr/lib/apache2/modules"))
    with tempfile.TemporaryDirectory(prefix="friendica-object-cache-") as directory:
        state = pathlib.Path(directory)
        state.chmod(0o755)
        (state / "cache").mkdir(mode=0o777)
        (state / "cache").chmod(0o777)
        (state / "count").write_text("0")
        (state / "mode").write_text("public")
        (state / "ttl").write_text("60")
        origin_port, proxy_port = port(), port()
        sample = (ROOT / "mods/sample-activitypub-object-cache.conf").read_text()
        (state / "cache.conf").write_text(sample.replace("/var/cache/apache2/friendica-objects", str(state / "cache")))
        load = "\n".join(
            f'LoadModule {name}_module "{modules}/mod_{name}.so"'
            for name in ("mpm_event", "authz_core", "proxy", "proxy_http", "cache", "cache_disk", "setenvif")
        )
        user = "User www-data\nGroup www-data" if os.geteuid() == 0 else ""
        config = state / "httpd.conf"
        config.write_text(f'''ServerRoot "{state}"
ServerName localhost
Listen 127.0.0.1:{proxy_port}
PidFile "{state}/httpd.pid"
ErrorLog "{state}/error.log"
LogLevel warn
{load}
{user}
StartServers 1
ServerLimit 1
ThreadsPerChild 32
MaxRequestWorkers 32
MinSpareThreads 1
MaxSpareThreads 16
ProxyRequests Off
ProxyPass / http://127.0.0.1:{origin_port}/
ProxyPassReverse / http://127.0.0.1:{origin_port}/
Include "{state}/cache.conf"
''')
        subprocess.run([apache, "-t", "-f", str(config)], check=True)
        processes = []
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())

        def request(path="/objects/example", headers=None, method="GET"):
            req = urllib.request.Request(
                f"http://127.0.0.1:{proxy_port}{path}",
                headers={"Accept": "application/activity+json", **(headers or {})},
                method=method,
            )
            try:
                response = opener.open(req, timeout=15)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                return response.status, response.read(), response.headers

        def count():
            return int((state / "count").read_text())

        try:
            with (state / "origin.log").open("w") as log:
                processes.append(subprocess.Popen(
                    [php, "-S", f"127.0.0.1:{origin_port}", str(ROOT / "tests/Fixtures/activitypub-object-cache.php")],
                    env={**os.environ, "FRIENDICA_CACHE_TEST_STATE": str(state)}, stdout=log, stderr=log,
                ))
                wait_for_port(origin_port, processes[-1])
                processes.append(subprocess.Popen([apache, "-f", str(config), "-DFOREGROUND"]))
                wait_for_port(proxy_port, processes[-1])

                first = request()
                assert first[0] == 200
                assert request()[1] == first[1]
                assert count() == 1, "Warm read reached PHP"
                assert request(method="HEAD")[1] == b""
                assert request()[1] == first[1], "HEAD changed the cached representation"
                conditional = request(headers={"If-None-Match": '"example-public"'})
                assert conditional[0] == 304
                assert count() == 1, "Conditional cache hit reached PHP"
                start = time.monotonic()
                with concurrent.futures.ThreadPoolExecutor(max_workers=25) as pool:
                    responses = list(pool.map(lambda _: request(), range(100)))
                assert all(r[0] == 200 and r[1] == first[1] for r in responses)
                assert count() == 1, "Warm burst reached PHP"
                print(f"Warm burst: 100 requests, 0 additional PHP renders, {time.monotonic() - start:.3f}s")

                # Warm anonymous entries must never satisfy signed/session requests.
                for header in ("Authorization", "Cookie", "Signature", "Signature-Input"):
                    before = count()
                    for _ in range(2):
                        assert request(headers={header: "example"})[0] == 200
                    assert count() == before + 2, f"{header} did not bypass the cache"
                assert request()[1] == first[1], "Bypass response replaced anonymous cache"

                for name, path, method, headers, status in (
                    ("query", "/objects/example?account=other", "GET", {}, 200),
                    ("HTML", "/objects/example", "GET", {"Accept": "text/html"}, 302),
                    ("write", "/objects/example", "POST", {}, 200),
                    ("replies", "/objects/example/replies", "GET", {}, 200),
                    ("inbox", "/inbox", "POST", {}, 200),
                    ("profile", "/profile/example", "GET", {}, 200),
                ):
                    before = count()
                    for _ in range(2):
                        assert request(path, headers, method)[0] == status
                    assert count() == before + 2, f"{name} was unexpectedly cached"

                for mode, status in (("private", 404), ("missing", 404), ("deleted", 410), ("error", 500), ("cookie", 200)):
                    (state / "mode").write_text(mode)
                    before = count()
                    for _ in range(2):
                        assert request(f"/objects/{mode}")[0] == status
                    assert count() == before + 2, f"{mode} response was cached"

                # Deletion/visibility changes are observed after the short freshness window.
                (state / "mode").write_text("public")
                (state / "ttl").write_text("2")
                assert request("/objects/expiry")[0] == 200
                (state / "mode").write_text("deleted")
                time.sleep(2.1)
                assert request("/objects/expiry")[0] == 410
                (state / "mode").write_text("private")
                assert request("/objects/expiry")[0] == 404

                (state / "mode").write_text("public")
                (state / "ttl").write_text("0")
                before = count()
                request("/objects/disabled")
                request("/objects/disabled")
                assert count() == before + 2, "Disabled policy was cached"
                print("PASS: signatures, authorization, cookies, HTML, queries, writes, private/error/deleted responses, expiry and disabled policy")
        except Exception:
            for log_name in ("error.log", "origin.log"):
                if (state / log_name).exists():
                    print((state / log_name).read_text()[-4000:])
            raise
        finally:
            for process in reversed(processes):
                process.terminate()
                try:
                    process.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


if __name__ == "__main__":
    run()
