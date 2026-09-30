"""Exercise the real trial HTTP server, cookies, CSRF, mutations and role scope."""
import http.cookiejar
import os
from pathlib import Path
import re
import socket
import sqlite3
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

root = Path(__file__).resolve().parents[3]
demo = root / "tests/erp_runtime/demo"
env = dict(os.environ, ERP_DEMO="1")
subprocess.run(["php", str(demo / "setup.php")], env=env, check=True)
# Refuse to exercise an unrelated process on the trial port.
with socket.socket() as sock:
    assert sock.connect_ex(("127.0.0.1", 8080)) != 0, "Port 8080 must be unused for this test"
log = open(demo / "state/smoke-server.log", "w")
server = subprocess.Popen(["php", "-S", "127.0.0.1:8080", "-t", str(demo / "public"), str(demo / "router.php")], cwd=root, env=env, stdout=log, stderr=log)
client = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
base = "http://localhost:8080"

def request(path, data=None, expected=200, referer=None):
    req = urllib.request.Request(base + path, None if data is None else urllib.parse.urlencode(data).encode())
    if referer:
        req.add_header("Referer", base + referer)
    try:
        response = client.open(req, timeout=10)
    except urllib.error.HTTPError as exc:
        response = exc
    body = response.read().decode()
    assert response.code == expected, (path, response.code, expected, body[:200])
    return body

def token(body):
    return re.search(r'name="_token" value="([^"]+)"', body).group(1)

def enter(role):
    return request("/demo/enter", {"_token": token(request("/")), "role": role})

try:
    for _ in range(50):
        try:
            request("/demo/health")
            break
        except urllib.error.URLError:
            time.sleep(.1)
    else:
        raise AssertionError("Trial server did not start")
    landing = request("/")
    assert "جرّب النظام بنفسك" in landing
    if os.getenv("ERP_RENDER_DIR"):
        folder = Path(os.environ["ERP_RENDER_DIR"])
        folder.mkdir(parents=True, exist_ok=True)
        (folder / "trial.html").write_text(landing)
    request("/demo/enter", {"role": "owner"}, expected=419)
    request("/state/app.key", expected=404)
    request("/tests/erp_runtime/demo/state/demo.sqlite", expected=404)
    request("/api/profile", expected=404)
    request("/erp/workspace.css")
    assert "نائب المدير" in enter("deputy")
    for screen in ["", "branches", "inventory", "employees", "payroll", "orders", "audit", "purchases", "production", "finance"]:
        assert "نسخة تجربة" in request("/erp/" + screen)
    request("/erp/accounts", expected=403)
    stamp = uuid.uuid4().hex
    data = {"_token": token(request("/erp/purchases")), "request_key": stamp,
            "supplier_id": "1", "warehouse_id": "1", "invoice_number": "TRIAL-" + stamp,
            "invoice_date": time.strftime("%Y-%m-%d"), "notes": "HTTP trial test",
            "lines[0][item_id]": "1", "lines[0][quantity]": "1", "lines[0][unit_cost]": "210"}
    assert "تم استلام الفاتورة" in request("/erp/purchases", data, referer="/erp/purchases")
    assert "TRIAL-" + stamp in request("/erp/purchases")
    assert "تم ترحيل الدفعة" in request("/erp/production", {
        "_token": token(request("/erp/production")), "request_key": uuid.uuid4().hex,
        "recipe_id":"1", "warehouse_id":"2", "factor":"1", "actual_output":"1", "notes":"HTTP trial batch"
    }, referer="/erp/production")
    assert "مدير المنصورة" in enter("branch")
    request("/erp/finance", expected=403)
    request("/erp/inventory?branch=2", expected=403)
    assert "مخزن المحلة" not in request("/erp/inventory")
    enter("owner")
    assert "الحسابات والصلاحيات" in request("/erp/accounts")
    # Real password-based staff login also works after owner creates an account.
    email = "trial-" + stamp + "@example.test"
    password = uuid.uuid4().hex
    assert "تم حفظ حساب ERP" in request("/erp/accounts", {
        "_token":token(request("/erp/accounts")), "name":"HTTP trial account", "email":email,
        "role":"deputy_manager", "active":"1", "permissions[]":"inventory.manage",
        "password":password, "password_confirmation":password,
    }, referer="/erp/accounts")
    request("/erp/logout", {"_token":token(request("/erp/"))})
    assert "HTTP trial account" in request("/erp/login", {
        "_token":token(request("/erp/login")), "email":email,"password":password,
    })
    db = sqlite3.connect(demo / "state/demo.sqlite")
    assert db.execute("select count(*) from erp_purchases where request_key=?", (stamp,)).fetchone()[0] == 1
    assert db.execute("select sum(debit_minor)-sum(credit_minor) from erp_journal_lines").fetchone()[0] == 0
    before = db.execute("select count(*) from erp_purchases").fetchone()[0]
    subprocess.run(["php", str(demo / "setup.php")], env=env, check=True)
    assert db.execute("select count(*) from erp_purchases").fetchone()[0] == before
    db.close()
    print("Trial HTTP passed: 3 roles, CSRF, private paths, purchase, production, real staff login, balanced ledger, persistent data.")
finally:
    server.terminate()
    server.wait(timeout=10)
    log.close()
