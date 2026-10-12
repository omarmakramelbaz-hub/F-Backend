#!/usr/bin/env python3
"""Fill the root launcher's immutable payload pins from the final Git files."""
import hashlib
from pathlib import Path
import sys

def blob(data):return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()

def main(root):
    names={
        'REPLACE_MANIFEST_BLOB':'deployment/desktop_dashboard_server_manifest.json',
        'REPLACE_APP_BLOB':'deployment/apply_desktop_dashboard_server.py',
        'REPLACE_PHP_BLOB':'deployment/desktop_dashboard_release.php',
    }
    path=root/'deployment/apply_desktop_dashboard_server_root.sh';text=path.read_text()
    for token,relative in names.items():
        if text.count(token)!=1:raise ValueError('Expected exactly one unset payload pin')
        text=text.replace(token,blob((root/relative).read_bytes()))
    path.write_text(text)
    print(blob(path.read_bytes()))

if __name__=='__main__':main(Path(sys.argv[1]).resolve())
