"""Run wallet settlement tests on an isolated disposable MariaDB instance.

Usage: python3 tests/wallet_topup_settlement/native-run.py extracted-mariadb-root php-executable autoload.php
The PHP executable must provide pdo_mysql, pdo_sqlite and the supplied Illuminate autoload.
"""
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time


def main():
    if len(sys.argv) != 4:
        raise SystemExit(__doc__)
    root, php, autoload = [Path(value).resolve() for value in sys.argv[1:]]
    data = Path(tempfile.mkdtemp(prefix="wallet-settlement-mariadb-"))
    env = os.environ.copy()
    env["LD_LIBRARY_PATH"] = str(root / "usr/lib/x86_64-linux-gnu")
    args = [str(root / "usr/sbin/mariadbd"), "--no-defaults", "--user=root",
            "--basedir=" + str(root / "usr"), "--datadir=" + str(data),
            "--lc-messages-dir=" + str(root / "usr/share/mysql"),
            "--innodb-use-native-aio=0", "--skip-grant-tables"]
    sql = "CREATE DATABASE mysql;\nUSE mysql;\n"
    for filename in ["mysql_system_tables.sql", "mysql_system_tables_data.sql"]:
        sql += (root / "usr/share/mysql" / filename).read_text() + "\n"
    server = None
    try:
        with (data / "database.log").open("w") as log:
            subprocess.run(args + ["--bootstrap"], input=sql, encoding="utf8",
                           stdout=log, stderr=log, env=env, check=True, timeout=30)
            with socket.socket() as free:
                free.bind(("127.0.0.1", 0))
                port = free.getsockname()[1]
            server = subprocess.Popen(args + ["--bind-address=127.0.0.1", "--port=" + str(port),
                                      "--socket=", "--pid-file=" + str(data / "mariadb.pid")],
                                      env=env, stdout=log, stderr=log)
            for _ in range(200):
                if server.poll() is not None:
                    raise RuntimeError("Disposable wallet settlement test database stopped.")
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=.2):
                        break
                except OSError:
                    time.sleep(.05)
            else:
                raise RuntimeError("Disposable wallet settlement test database did not become ready.")
            test_env = os.environ.copy()
            test_env["WALLET_SETTLEMENT_TEST_PHP"] = str(php)
            subprocess.run([str(php), str(Path(__file__).with_name("run.php")), str(port), str(autoload)],
                           check=True, timeout=120, env=test_env)
    except Exception:
        print((data / "database.log").read_text()[-3000:], file=sys.stderr)
        raise
    finally:
        if server is not None:
            server.terminate()
            server.wait(timeout=20)
        shutil.rmtree(data)


if __name__ == "__main__":
    main()
