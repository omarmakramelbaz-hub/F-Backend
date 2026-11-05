#!/usr/bin/env python3
"""Read-only: secret files in the site's public folder (anything there can be downloaded), and whether the site
serves them. For a Google service account key it prints the project, the account and the first 8 characters of the
key's id (what its project's console lists, to revoke it) - never the key itself. Nothing is changed.

Run as root on srv1836524:
  python3 -I -B deployment/check_public_secrets_root.py
"""
import json
import os
from pathlib import Path
import re
import socket
import ssl
import urllib.error
import urllib.parse
import urllib.request

HOST = 'srv1836524'
SITE = Path('/home/fasakha/public_html')
ORIGIN = 'https://fasakhaninja.com'
RISKY = re.compile(r'(credential|service.?account|firebase.*\.json|private|secret|\.env$|\.env\.|\.pem$|\.key$|\.p12$|\.sql$|\.sql\.gz$'
                   r'|\.bak$|-old$|\.old$|backup|dump|\.zip$|\.tar$|\.tar\.gz$|\.tgz$)', re.IGNORECASE)


class Stop(Exception):
    pass


def candidates(folder):
    """Files directly in the public folder (and one level down) whose names look like secrets or backups."""
    found = []
    for path in sorted(folder.iterdir()) if folder.is_dir() else []:
        if path.is_file() and not path.is_symlink() and RISKY.search(path.name):
            found.append(path)
        elif path.is_dir() and not path.is_symlink() and path.name not in ('vendor', 'node_modules'):
            for inner in sorted(path.iterdir())[:2000]:
                if inner.is_file() and not inner.is_symlink() and RISKY.search(inner.name):
                    found.append(inner)
    return found


def describe(path):
    """What a file is, without its secrets: a service account key's project, account and key id prefix."""
    facts = {'size': path.stat().st_size}
    if path.stat().st_size <= 64 * 1024:
        try:
            data = json.loads(path.read_text(encoding='utf-8'))
        except (OSError, ValueError, UnicodeDecodeError):
            data = None
        if isinstance(data, dict) and data.get('type') == 'service_account':
            facts.update({'google_key': True, 'has_private_key': bool(data.get('private_key')), 'project': data.get('project_id'),
                          'account': data.get('client_email'), 'key_id_starts': str(data.get('private_key_id') or '')[:8]})
    return facts


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *arguments, **keywords):
        return None


def served(relative, opener=None):
    """The site's answer to a request for the file: status and type only (a HEAD request has no body)."""
    url = ORIGIN + '/' + urllib.parse.quote(relative)
    opener = opener or urllib.request.build_opener(NoRedirect, urllib.request.HTTPSHandler(context=ssl.create_default_context()))
    request = urllib.request.Request(url, method='HEAD', headers={'User-Agent': 'go-erp-secret-check'})
    try:
        with opener.open(request, timeout=10) as response:
            return {'status': response.status, 'type': response.headers.get('Content-Type', '')}
    except urllib.error.HTTPError as error:
        return {'status': error.code, 'type': error.headers.get('Content-Type', '') if error.headers else ''}
    except (urllib.error.URLError, OSError) as error:
        return {'status': 'unreachable', 'type': type(error).__name__}


def report(site=SITE, ask=served):
    lines = ['GO_ERP_PUBLIC_SECRETS_CHECK']
    public = site / 'public'
    found = candidates(public)
    lines.append('files in public/ that look like secrets or backups: {}'.format(len(found)))
    for path in found:
        relative = str(path.relative_to(public))
        facts = describe(path)
        facts['served'] = ask(relative)
        lines.append('  public/' + relative + ' ' + json.dumps(facts, ensure_ascii=False, sort_keys=True))
    used = site / 'storage/app/firebase_credentials.json'
    lines.append('the key the site uses (storage/app/firebase_credentials.json, not downloadable): '
                 + (json.dumps(describe(used), ensure_ascii=False, sort_keys=True) if used.is_file() else 'missing'))
    return lines


def main():
    if os.geteuid() != 0 or socket.gethostname().split('.')[0] != HOST:
        raise Stop('GO_ERP_PUBLIC_SECRETS_ROOT_AND_HOST_REQUIRED')
    print('\n'.join(report()), flush=True)


if __name__ == '__main__':
    try:
        main()
    except Stop as error:
        print(str(error), flush=True)
        raise SystemExit(1)
