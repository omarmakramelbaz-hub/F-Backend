#!/usr/bin/env python3
"""Read-only domain migration inventory. Does not boot Laravel or read the DB.

Prints filenames, hashes, domain counts, and an allowlist of non-secret settings.
Never prints source lines, credentials, URL paths/query strings, or env secrets.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import socket
import sys
from urllib.parse import urlsplit

ROOT = Path('/home/fasakha/public_html')
DOMAIN = re.compile(r'(?i)(?<![a-z0-9_.-])(?:www\.)?(?:fasakhaninja|faskhansta)\.com(?![a-z0-9_.-])')
SECRET_KEY = re.compile(r'(?i)secret|password|token|credential|private|api_key|hmac|dsn')
SUFFIXES = {'.php', '.js', '.ts', '.json', '.html', '.css', '.xml', '.txt', '.webmanifest', '.dart'}
EXCLUDED = {'vendor', 'node_modules', '.git', 'storage', 'uploads', 'upload', 'media', 'images', 'videos', 'fonts', 'logs', 'backups', 'backup', 'cache', 'plugins', '.well-known'}
IMPORTANT = [
    '.htaccess', 'public/.htaccess', 'index.php', 'public/index.php',
    'app/Providers/AppServiceProvider.php', 'app/Http/Kernel.php',
    'app/Http/Middleware/VerifyCsrfToken.php', 'config/app.php',
    'config/session.php', 'config/services.php', 'config/cors.php',
    'app/Http/Controllers/Payment/PaymobController.php',
    'routes/web.php', 'routes/api.php', 'composer.json',
]


def emit(section, value):
    print(section + ' ' + json.dumps(value, ensure_ascii=True, sort_keys=True), flush=True)


def safe_origin(value):
    """Never print userinfo, path, query, fragment or arbitrary env values."""
    value = value.strip().strip('\"\'')
    if value.lower() in ('', 'null', '(null)', 'true', 'false', 'none'):
        return value.lower() or '(empty)'
    if re.fullmatch(r'\.?[a-z0-9.-]+(?::[0-9]{1,5})?', value, re.I):
        return value.lower()
    try:
        url = urlsplit(value)
        if url.scheme in ('http', 'https') and url.hostname:
            host = url.hostname
            if not re.fullmatch(r'[a-z0-9.-]+', host, re.I):
                return '(nonstandard host)'
            return url.scheme + '://' + host + (':' + str(url.port) if url.port else '')
    except ValueError:
        pass
    return '(configured; value omitted)'


def env_inventory(text):
    allow = {'APP_URL', 'ASSET_URL', 'SESSION_DOMAIN', 'SESSION_SECURE_COOKIE', 'SESSION_SAME_SITE'}
    result, referenced = {}, []
    for line in text.splitlines():
        match = re.match(r'^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*)$', line)
        if not match:
            continue
        key, value = match.groups()
        if SECRET_KEY.search(key):
            continue
        if key in allow:
            result[key] = safe_origin(value)
        elif DOMAIN.search(value):
            referenced.append(key)
    return {'settings': result, 'other_domain_setting_names': sorted(set(referenced))}


def readable(root, path):
    if path.is_symlink() or not path.is_file():
        return False
    try:
        path.resolve().relative_to(root.resolve())
    except ValueError:
        return False
    return path.stat().st_size <= 4 * 1024 * 1024


def file_info(root, path):
    data = path.read_bytes()
    text = data.decode('utf-8', errors='replace')
    counts = {}
    for match in DOMAIN.finditer(text):
        key = match.group(0).lower()
        counts[key] = counts.get(key, 0) + 1
    info = {'file': str(path.relative_to(root)), 'sha256': hashlib.sha256(data).hexdigest(), 'bytes': len(data)}
    if counts:
        info['domain_counts'] = counts
    features = {key: text.count(key) for key in ['forceRootUrl', 'forceScheme', 'APP_URL', 'ASSET_URL', 'SESSION_DOMAIN', 'notification_url', 'redirection_url', 'temporarySignedRoute', 'hasValidSignature'] if key in text}
    if features:
        info['features'] = features
    if path.name == 'AppServiceProvider.php':
        info['boot_methods'] = len(re.findall(r'function\s+boot\s*\(', text))
    if path.name == 'app.php' and path.parent.name == 'config':
        info['app_providers'] = sorted(set(re.findall(r'App\\Providers\\[A-Za-z0-9_]+', text)))
    return info


def inspect(root):
    env = root / '.env'
    if readable(root, env):
        emit('ENV', env_inventory(env.read_text(errors='replace')))
    else:
        emit('ENV', {'available': False})
    emit('CONFIG_CACHE', {'exists': (root / 'bootstrap/cache/config.php').is_file()})
    for name in IMPORTANT:
        path = root / name
        if readable(root, path):
            emit('FILE', file_info(root, path))
        else:
            emit('FILE', {'file': name, 'available': False})

    seen, skipped = set(IMPORTANT), 0
    refs, features = 0, 0
    for directory in ['app', 'config', 'resources', 'routes', 'public']:
        base = root / directory
        if base.is_symlink() or not base.is_dir():
            continue
        for current, dirs, files in os.walk(base, followlinks=False):
            dirs[:] = sorted(d for d in dirs if d not in EXCLUDED and not d.startswith('.') and not (Path(current) / d).is_symlink())
            for name in sorted(files):
                path = Path(current) / name
                relative = str(path.relative_to(root))
                if relative in seen or path.suffix.lower() not in SUFFIXES or name.startswith('.'):
                    continue
                if not readable(root, path):
                    skipped += 1
                    continue
                info = file_info(root, path)
                if 'domain_counts' in info:
                    refs += 1
                    if refs <= 250:
                        emit('DOMAIN_REF', info)
                elif 'features' in info and any(k in info['features'] for k in ['forceRootUrl', 'forceScheme', 'notification_url', 'redirection_url', 'temporarySignedRoute', 'hasValidSignature']):
                    features += 1
                    if features <= 80:
                        emit('INTEGRATION_REF', info)
    emit('SCAN', {'domain_files': refs, 'integration_files': features, 'skipped_large_or_symlink_files': skipped, 'truncated': refs > 250 or features > 80, 'database_scanned': False, 'source_changed': False})


def main():
    if len(sys.argv) != 1:
        raise SystemExit('STOP: This audit accepts no arguments.')
    if socket.gethostname().split('.')[0] != 'srv1836524':
        raise SystemExit('STOP: Run on srv1836524.')
    if not ROOT.is_dir() or ROOT.is_symlink() or ROOT.resolve() != ROOT:
        raise SystemExit('STOP: Unexpected application directory.')
    print('FASKHANSTA_DOMAIN_AUDIT_BEGIN', flush=True)
    inspect(ROOT)
    print('FASKHANSTA_DOMAIN_AUDIT_COMPLETE', flush=True)


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError) as exc:
        # Exception messages may contain file contents; return only the class.
        raise SystemExit('STOP: audit failed: ' + type(exc).__name__)
