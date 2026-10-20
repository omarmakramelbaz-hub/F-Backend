"""Run the original Laravel/MariaDB integration fixture in one process network namespace.

Usage: python3 native-run.py mariadb-extracted-root php-executable generated-application [--print-ledger]
The extracted MariaDB root contains usr/sbin/mariadbd and usr/share/mysql.
"""
import os
import json
import re
from contextlib import contextmanager
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time

DEFAULT_FIXTURES = ["run.php", "legacy.php", "wallet-cash.php", "outbox-review.php", "print-ledger.php", "settings.php",
                    "user-accounts.php", "order-board-component-run.php", "enrollment-run.php", "connected-request-actor.php",
                    "core-authority.php", "created-scopes.php"]
REQUIRED_COUNTS = {
    "run.php": [(r"([0-9]+) checks passed using the original Laravel application and real MariaDB", 1)],
    "legacy.php": [(r"([0-9]+) legacy schema checks passed", 1)],
    "wallet-cash.php": [(r"WALLET_CASH_CHECKS=([0-9]+)", 70)],
    "outbox-review.php": [(r"DESKTOP_OUTBOX_REVIEW_COMPLETE ([0-9]+)", 1)],
    "print-ledger.php": [(r"([0-9]+) private print-ledger checks passed using original Laravel and disposable MariaDB", 205)],
    "settings.php": [(r"DESKTOP_SETTINGS_COMPLETE ([0-9]+)", 112)],
    "user-accounts.php": [(r"USER_ACCOUNT_CHECKS_COMPLETE ([0-9]+)", 91)],
    "order-board-component-run.php": [(r"WALLET_CASH_CHECKS=([0-9]+)", 70), (r"ORDER_BOARD_COMPONENT_CHECKS=([0-9]+)", 41),
                                      (r"ORDER_BOARD_RECONCILIATION_CHECKS=([0-9]+)", 29)],
    "enrollment-run.php": [(r"WALLET_CASH_CHECKS=([0-9]+)", 70), (r"ENROLLMENT_BINDING_CHECKS=([0-9]+)", 45)],
    "core-authority.php": [(r"CORE_CURRENT_AUTHORITY_CHECKS_COMPLETE ([0-9]+)", 166)],
    "created-scopes.php": [(r"CREATED_CATALOG_SCOPE_CHECKS=([0-9]+)", 45)],
    "connected-request-actor.php": [],
}


def verify_fixture_receipts(fixture, output):
    """An exit code cannot substitute for a complete, unambiguous acceptance receipt."""
    if fixture not in REQUIRED_COUNTS:
        raise RuntimeError("An application fixture has no required completion receipt: " + fixture)
    lines = output.splitlines()
    for pattern, minimum in REQUIRED_COUNTS[fixture]:
        matches = [match for line in lines if (match := re.fullmatch(pattern, line))]
        if len(matches) != 1 or int(matches[0].group(1)) < minimum:
            raise RuntimeError("An original application fixture receipt is missing, incomplete or duplicated: " + fixture)
    if fixture == "connected-request-actor.php":
        prefix = "CONNECTED_REQUEST_ACTOR_COMPLETE "
        receipts = [line[len(prefix):] for line in lines if line.startswith(prefix)]
        if len(receipts) != 1:
            raise RuntimeError("The connected request actor fixture must complete exactly once.")
        receipt = json.loads(receipts[0])
        numbers = {"checks": 35, "probeActor": 1, "originalRequestActor": 10}
        flags = {"sameRequestGuard": True, "actualOriginalController": True, "separateHttpSessions": True,
                 "businessTransactionWrapped": False, "productionEnvironmentLoaded": False}
        if not isinstance(receipt, dict) or any(type(receipt.get(key)) is not int or receipt[key] != value for key, value in numbers.items()) \
                or any(receipt.get(key) is not value for key, value in flags.items()):
            raise RuntimeError("The connected request actor acceptance receipt differs.")


@contextmanager
def disposable_database(root):
    """Own a fresh datadir and process; never reset another fixture's retained schemas."""
    data = Path(tempfile.mkdtemp(prefix="fasakhansta-mariadb-test-"))
    (data / "tmp").mkdir(mode=0o700)
    env = os.environ.copy()
    env["LD_LIBRARY_PATH"] = str(root / "usr/lib/x86_64-linux-gnu")
    args = [str(root / "usr/sbin/mariadbd"), "--no-defaults", "--user=root",
            "--basedir=" + str(root / "usr"), "--datadir=" + str(data),
            "--tmpdir=" + str(data / "tmp"),
            "--lc-messages-dir=" + str(root / "usr/share/mysql"),
            "--innodb-use-native-aio=0", "--skip-grant-tables"]
    server = None
    try:
        sql = "CREATE DATABASE mysql;\nUSE mysql;\n"
        for file in ["mysql_system_tables.sql", "mysql_system_tables_data.sql"]:
            sql += (root / "usr/share/mysql" / file).read_text() + "\n"
        with (data / "test-database.log").open("w") as log:
            subprocess.run(args + ["--bootstrap"], input=sql, encoding="utf8", stdout=log,
                           stderr=log, env=env, check=True)
            with socket.socket() as free:
                free.bind(("127.0.0.1", 0))
                port = free.getsockname()[1]
            server = subprocess.Popen(args + ["--bind-address=127.0.0.1", "--port=" + str(port),
                                              "--socket=", "--pid-file=" + str(data / "mariadb.pid")],
                                      env=env, stdout=log, stderr=log)
            for _ in range(200):
                if server.poll() is not None:
                    raise RuntimeError("Disposable test database stopped.")
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=.2):
                        break
                except OSError:
                    time.sleep(.05)
            else:
                raise RuntimeError("Disposable test database did not become ready.")
            yield port
    finally:
        if server is not None:
            if server.poll() is None:
                server.terminate()
                try:
                    server.wait(timeout=20)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=20)
        shutil.rmtree(data)


def run_fixtures(root, php, application, fixtures):
    root, php, application = root.resolve(), php.resolve(), application.resolve()
    for fixture in fixtures:
        if not Path(__file__).with_name(fixture).is_file():
            raise RuntimeError("A required original application fixture is missing: " + fixture)
        if fixture not in REQUIRED_COUNTS:
            raise RuntimeError("An application fixture has no required completion receipt: " + fixture)

    def run(fixture, port):
        file = Path(__file__).with_name(fixture)
        output = []
        with subprocess.Popen([str(php), str(file), str(application), str(port)], stdout=subprocess.PIPE,
                              stderr=subprocess.STDOUT, text=True, encoding="utf8", errors="replace", bufsize=1) as process:
            for line in process.stdout:
                print(line, end="", flush=True)
                output.append(line)
            code = process.wait()
            if code:
                raise subprocess.CalledProcessError(code, process.args)
        verify_fixture_receipts(fixture, "".join(output))

    with disposable_database(root) as shared_port:
        for fixture in fixtures:
            if fixture == "print-ledger.php":
                # Other cohorts intentionally retain the fixed effects schema. The
                # print fixture proves initial creation and retention on its own server.
                with disposable_database(root) as print_port:
                    run(fixture, print_port)
            else:
                run(fixture, shared_port)


if __name__ == "__main__":
    if len(sys.argv) not in (4, 5) or (len(sys.argv) == 5 and sys.argv[4] != "--print-ledger"):
        raise SystemExit("Expected MariaDB root, PHP, application and optional --print-ledger.")
    fixtures = ["print-ledger.php"] if len(sys.argv) == 5 else DEFAULT_FIXTURES
    try:
        run_fixtures(*map(Path, sys.argv[1:4]), fixtures)
    except subprocess.CalledProcessError as error:
        raise SystemExit(error.returncode)
