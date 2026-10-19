"""Run the original Laravel/MariaDB integration fixture in one process network namespace.

Usage: python3 native-run.py mariadb-extracted-root php-executable generated-application [--print-ledger]
The extracted MariaDB root contains usr/sbin/mariadbd and usr/share/mysql.
"""
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time

root, php, application = map(Path, sys.argv[1:4])
if len(sys.argv) not in (4, 5) or (len(sys.argv) == 5 and sys.argv[4] != "--print-ledger"):
    raise SystemExit("Expected MariaDB root, PHP, application and optional --print-ledger.")
fixtures = ["print-ledger.php"] if len(sys.argv) == 5 else ["run.php", "legacy.php", "wallet-cash.php", "outbox-review.php"]
root, php, application = root.resolve(), php.resolve(), application.resolve()
data = Path(tempfile.mkdtemp(prefix="fasakhansta-mariadb-test-"))
(data / "tmp").mkdir(mode=0o700)
env = os.environ.copy()
env["LD_LIBRARY_PATH"] = str(root / "usr/lib/x86_64-linux-gnu")
args = [str(root / "usr/sbin/mariadbd"), "--no-defaults", "--user=root",
        "--basedir=" + str(root / "usr"), "--datadir=" + str(data),
        "--tmpdir=" + str(data / "tmp"),
        "--lc-messages-dir=" + str(root / "usr/share/mysql"),
        "--innodb-use-native-aio=0", "--skip-grant-tables"]
sql = "CREATE DATABASE mysql;\nUSE mysql;\n"
for file in ["mysql_system_tables.sql", "mysql_system_tables_data.sql"]:
    sql += (root / "usr/share/mysql" / file).read_text() + "\n"
server = None
try:
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
        for fixture in fixtures:
            if not Path(__file__).with_name(fixture).exists():
                continue
            result = subprocess.run([str(php), str(Path(__file__).with_name(fixture)), str(application), str(port)])
            if result.returncode:
                raise SystemExit(result.returncode)
finally:
    if server is not None:
        server.terminate()
        server.wait(timeout=20)
    shutil.rmtree(data)
