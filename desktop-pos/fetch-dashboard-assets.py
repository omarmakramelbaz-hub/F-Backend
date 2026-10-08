"""Build-time mirror of the original layout's public CSS, fonts and browser scripts.

No application API, business data or credentials are fetched. A manifest records source and cached hashes.
"""
import hashlib
import html
import io
import concurrent.futures
import json
import os
from pathlib import Path
import re
import urllib.parse
import urllib.request
import zipfile

project = Path(__file__).resolve().parent.parent
target = project / "public/dashboard/vendor/desktop-external"
target.mkdir(parents=True, exist_ok=True)
manifest_file = target / "manifest.json"
previous = json.loads(manifest_file.read_text()) if manifest_file.exists() else {"format": 1, "assets": {}}
assets = dict(previous["assets"])
for entry in assets.values():
    existing = target / entry["path"]
    if not existing.is_file() or hashlib.sha256(existing.read_bytes()).hexdigest() != entry["sha256"]:
        raise RuntimeError("A previously pinned public layout resource changed.")
agent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/142.0.0.0 Safari/537.36"

def mirror(url):
    url = html.unescape(url)
    if url in assets:
        return assets[url]["path"]
    existing = previous["assets"].get(url)
    if existing:
        cached = target / existing["path"]
        if cached.is_file() and hashlib.sha256(cached.read_bytes()).hexdigest() == existing["sha256"]:
            assets[url] = existing
            return existing["path"]
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != "https" or parsed.username or parsed.password:
        raise RuntimeError("Only public HTTPS assets can be mirrored.")
    if parsed.hostname == "fonts.googleapis.com":
        relative = "google-fonts/" + hashlib.sha256(url.encode()).hexdigest()[:24] + ".css"
    else:
        relative = parsed.hostname + "/" + parsed.path.lstrip("/")
    if ".." in Path(relative).parts or ":" in relative or "\\" in relative:
        raise RuntimeError("Unsafe asset path.")
    request = urllib.request.Request(url, headers={"User-Agent": agent})
    with urllib.request.urlopen(request, timeout=30) as response:
        data = response.read(8 * 1024 * 1024 + 1)
        resolved = response.geturl()
        mime = response.headers.get_content_type()
    if len(data) > 8 * 1024 * 1024 or mime in ("text/html", "application/xhtml+xml"):
        raise RuntimeError("Invalid static asset response: " + url)
    original_hash = hashlib.sha256(data).hexdigest()
    assets[url] = {"path": relative, "resolved_url": resolved, "source_sha256": original_hash}
    if relative.endswith(".css"):
        css = data.decode("utf-8")
        def dependency(match):
            value = match.group(2).strip()
            if not value or value.startswith(("data:", "#")):
                return match.group(0)
            resource = urllib.parse.urljoin(url, value)
            path = mirror(resource)
            local = os.path.relpath(target / path, (target / relative).parent).replace("\\", "/")
            # Keep original relative CSS bytes whenever their paths already match the mirrored tree.
            if not urllib.parse.urlsplit(value).scheme and not value.startswith("//"):
                return match.group(0)
            return 'url("' + local + '")'
        css = re.sub(r"url\(\s*(['\"]?)(.*?)\1\s*\)", dependency, css)
        data = css.encode("utf-8")
    destination = target / relative
    destination.parent.mkdir(parents=True, exist_ok=True)
    if destination.exists() and destination.read_bytes() != data:
        raise RuntimeError("Two source URLs produced different content at the same asset path.")
    destination.write_bytes(data)
    assets[url].update({"sha256": hashlib.sha256(data).hexdigest(), "bytes": len(data), "mime": mime})
    print("Cached", relative, flush=True)
    return relative

sources = set()
for template in (project / "resources/views/admin").rglob("*.blade.php"):
    markup = re.sub(r"<!--.*?-->", "", template.read_text(), flags=re.S)
    urls = re.findall(r"(?:src|href)\s*=\s*['\"]((?:https:)?//[^'\"]+)['\"]", markup)
    urls += re.findall(r"DesktopDashboardAssets::url\(['\"]((?:https:)?//[^'\"]+)['\"]\)", markup)
    for url in urls:
        if url.startswith("//"):
            url = "https:" + url
        if re.search(r"\.(?:js|css)(?:[?#]|$)", url) or url.startswith("https://fonts.googleapis.com/css"):
            sources.add(html.unescape(url))
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    list(pool.map(mirror, sorted(sources - {"https://cdn.ckeditor.com/4.14.0/standard/ckeditor.js"})))

# CKEditor's dynamically requested plugins, skin and Arabic/English translations must also be local.
ckeditor_url = "https://download.cksource.com/CKEditor/CKEditor/CKEditor%204.14.0/ckeditor_4.14.0_standard.zip"
with urllib.request.urlopen(ckeditor_url, timeout=30) as response:
    archive = response.read(4 * 1024 * 1024 + 1)
if len(archive) > 4 * 1024 * 1024:
    raise RuntimeError("Unexpected CKEditor archive size.")
archive_hash = hashlib.sha256(archive).hexdigest()
expected_archive = previous.get("distributions", {}).get("ckeditor", {}).get("sha256")
if expected_archive and archive_hash != expected_archive:
    raise RuntimeError("The pinned CKEditor distribution changed.")
ckeditor_root = "cdn.ckeditor.com/4.14.0/standard/"
with zipfile.ZipFile(io.BytesIO(archive)) as distribution:
    for item in distribution.infolist():
        if item.is_dir() or not item.filename.startswith("ckeditor/"):
            continue
        name = item.filename[len("ckeditor/"):]
        if name.startswith(("samples/", "adapters/")):
            continue
        if "/lang/" in "/" + name and Path(name).stem not in ("ar", "en"):
            continue
        if ".." in Path(name).parts or ":" in name or "\\" in name or item.file_size > 4 * 1024 * 1024:
            raise RuntimeError("Invalid CKEditor archive entry.")
        data = distribution.read(item)
        relative = ckeditor_root + name
        destination = target / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(data)
        digest = hashlib.sha256(data).hexdigest()
        assets["https://" + relative] = {"path": relative, "resolved_url": ckeditor_url,
            "source_sha256": digest, "sha256": digest, "bytes": len(data), "mime": "application/octet-stream"}

license_sources = {
    "axios": "https://cdn.jsdelivr.net/npm/axios@1.6.7/LICENSE",
    "select2": "https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/LICENSE.md",
    "summernote": "https://cdn.jsdelivr.net/npm/summernote@0.8.18/LICENSE",
    "font-awesome": "https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/LICENSE.txt",
    "jstree": "https://cdn.jsdelivr.net/npm/jstree@3.2.1/LICENSE-MIT",
    "rateyo": "https://cdn.jsdelivr.net/npm/rateyo@2.3.2/LICENSE",
    "toastr": "https://raw.githubusercontent.com/CodeSeven/toastr/50092cc604850a16c985520b63df184d3e0b4086/LICENSE",
    "ionicons": "https://cdn.jsdelivr.net/npm/ionicons@2.0.1/LICENSE",
    "almarai": "https://raw.githubusercontent.com/google/fonts/main/ofl/almarai/OFL.txt",
    "pusher": "https://cdn.jsdelivr.net/npm/pusher-js@8.2.0/LICENCE",
    "sweetalert": "https://cdn.jsdelivr.net/npm/sweetalert@2.1.2/LICENSE.md",
    "firebase": "https://raw.githubusercontent.com/firebase/firebase-js-sdk/363b08a3786c134aba0c47b437a49f36f5466706/LICENSE",
    "bootstrap": "https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/LICENSE",
    "chartjs": "https://cdn.jsdelivr.net/npm/chart.js@2.7.1/LICENSE.md",
    "datatables": "https://raw.githubusercontent.com/DataTables/DataTables/1.10.12/license.txt",
    "font-awesome-4": "https://raw.githubusercontent.com/FortAwesome/Font-Awesome/v4.7.0/README.md",
}
licenses = {}
# Preserve completed public downloads if a license endpoint fails; builds reject incomplete manifests.
distributions = {"ckeditor": {"source": ckeditor_url, "sha256": archive_hash}}
manifest_file.write_text(json.dumps({"format": 1, "complete": False, "assets": assets,
    "licenses": previous.get("licenses", {}), "distributions": distributions}, indent=2) + "\n")
def license_text(item):
    name, url = item
    old = previous.get("licenses", {}).get(name)
    if old and old["source"] == url and (target / old["path"]).is_file():
        if hashlib.sha256((target / old["path"]).read_bytes()).hexdigest() == old["sha256"]:
            licenses[name] = old
            return
    with urllib.request.urlopen(url, timeout=30) as response:
        text = response.read(1024 * 1024).decode("utf-8")
    file = target / "licenses" / (name + ".txt")
    file.parent.mkdir(exist_ok=True)
    file.write_text(text)
    licenses[name] = {"source": url, "path": "licenses/" + file.name,
                      "sha256": hashlib.sha256(file.read_bytes()).hexdigest()}
    print("Retained license for", name, flush=True)
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    list(pool.map(license_text, license_sources.items()))
# Retain the complete OFL/MIT terms beside version 4's upstream attribution and license declaration.
font4 = (target / "stackpath.bootstrapcdn.com/font-awesome/4.7.0/fonts/fontawesome-webfont.svg").read_text()
if "Copyright Dave Gandy 2016. All rights reserved." not in font4:
    raise RuntimeError("Review the Font Awesome 4 attribution before rebuilding.")
font_terms = (target / licenses["font-awesome"]["path"]).read_text()
ofl = "SIL OPEN FONT LICENSE" + font_terms.split("SIL OPEN FONT LICENSE", 1)[1].split("\n--------", 1)[0]
mit = "Permission is hereby granted" + font_terms.split("# Code: MIT License", 1)[1].split("Permission is hereby granted", 1)[1].split("\n--------", 1)[0]
file = target / "licenses/font-awesome-4-terms.txt"
file.write_text("Font Awesome 4.7.0 by Dave Gandy\nCopyright Dave Gandy 2016. All rights reserved.\n\n"
    "Fonts: SIL OFL 1.1\n\n" + ofl.strip() + "\n\nCSS: MIT\n\n" + mit.strip() + "\n", newline="\n")
licenses["font-awesome-4-terms"] = {"source": license_sources["font-awesome-4"],
    "terms_source": license_sources["font-awesome"], "path": "licenses/" + file.name,
    "sha256": hashlib.sha256(file.read_bytes()).hexdigest()}
manifest_file.write_text(json.dumps({"format": 1, "complete": True, "assets": assets, "licenses": licenses,
    "distributions": distributions},
    ensure_ascii=False, indent=2, sort_keys=True) + "\n", newline="\n")
print("Mirrored", len(assets), "public layout dependencies.")
