#!/usr/bin/env python3
"""Fill the root launcher's immutable payload pins from the final Git files."""
import ast
import hashlib
from pathlib import Path
import re
import sys

def blob(data):return hashlib.sha1(b'blob '+str(len(data)).encode()+b'\0'+data).hexdigest()

def main(root):
    path=root/'deployment/apply_desktop_dashboard_server_root.sh';text=path.read_text()
    matches=list(re.finditer(r'^HELPER_PINS = (\{.*?^\})',text,re.M|re.S))
    if len(matches)!=1:raise ValueError('Expected one explicit payload pin map')
    match=matches[0];pins=ast.literal_eval(match[1])
    expected={'deployment/desktop_dashboard_server_manifest.json','deployment/apply_desktop_dashboard_server.py',
        'deployment/desktop_dashboard_release.php','deployment/install_whatsapp_inbox.py'}
    alternative='deployment/desktop_dashboard_server_manifest_expense_rejection.json'
    if alternative in pins:expected.add(alternative)
    if not isinstance(pins,dict)or set(pins)!=expected or any(not isinstance(value,str)for value in pins.values()):
        raise ValueError('The reviewed payload scope differs')
    updated={}
    for relative in pins:
        source=root/relative
        if source.is_symlink()or not source.is_file():raise ValueError('Payload must be a regular reviewed file')
        updated[relative]=blob(source.read_bytes())
    rendered='{\n'+''.join('    '+repr(name)+': '+repr(pin)+',\n'for name,pin in updated.items())+'}'
    path.write_text(text[:match.start(1)]+rendered+text[match.end(1):])
    print(blob(path.read_bytes()))

if __name__=='__main__':main(Path(sys.argv[1]).resolve())
