#!/usr/bin/env python3
"""Reproduce the root -> su session handoff using a synthetic secret."""
import errno
import os
from pathlib import Path
import pty
import pwd
import select
import signal
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
assert os.geteuid() == 0, 'Run this terminal regression with sudo/root'
OWNER = pwd.getpwnam('nobody')
SECRET = '0123456789abcdef0123456789abcdef'


def terminal_run(wrapper, app, commit, supplied):
    child, terminal = pty.fork()
    if child == 0:
        os.execv('/bin/bash', ['bash', str(wrapper), str(app), commit, OWNER.pw_name])
    output = bytearray()
    sent = False
    status = None
    deadline = time.monotonic() + 20
    try:
        while time.monotonic() < deadline:
            if select.select([terminal], [], [], 0.2)[0]:
                try:
                    data = os.read(terminal, 4096)
                except OSError as error:
                    if error.errno != errno.EIO:
                        raise
                    break
                if not data:
                    break
                output.extend(data)
                if not sent and b'App Secret: ' in output:
                    os.write(terminal, supplied.encode() + b'\n')
                    sent = True
            if status is None:
                finished, result = os.waitpid(child, os.WNOHANG)
                if finished:
                    status = result
                    # Drain buffered output on the next iteration, until PTY closes.
            elif not select.select([terminal], [], [], 0)[0]:
                break
        if status is None:
            finished, result = os.waitpid(child, os.WNOHANG)
            if not finished:
                os.kill(child, signal.SIGKILL)
                os.waitpid(child, 0)
                raise AssertionError('Terminal handoff timed out')
            status = result
        assert sent, 'The root prompt was not displayed: ' + repr(bytes(output))
        assert supplied.encode() not in output, 'The supplied secret was echoed'
        return os.waitstatus_to_exitcode(status), bytes(output)
    finally:
        os.close(terminal)


with tempfile.TemporaryDirectory(prefix='wa-terminal-test-') as directory:
    app = Path(directory)
    app.chmod(0o755)
    (app / 'deployment').mkdir()
    # A receiving fixture checks the real su session, stdin channel, and UID.
    installer = app / 'deployment/install_whatsapp_webhook.sh'
    installer.write_text('''#!/usr/bin/env bash
set -euo pipefail
[[ $(id -u) -ne 0 && "$#" -eq 3 && "$3" == --secret-stdin ]]
if { exec 3<>/dev/tty; } 2>/dev/null; then
    echo 'Expected su command to have no controlling terminal' >&2
    exit 1
fi
IFS= read -r received
[[ "$received" == 0123456789abcdef0123456789abcdef ]]
printf 'SECRET_HANDOFF_OK\\n'
''')
    subprocess.run(['git', 'init', '-q', str(app)], check=True)
    subprocess.run(['git', '-C', str(app), 'add', '.'], check=True)
    subprocess.run(['git', '-C', str(app), '-c', 'user.name=Terminal test', '-c',
                    'user.email=terminal-test@example.invalid', 'commit', '-qm', 'Fixture'], check=True)
    commit = subprocess.check_output(['git', '-C', str(app), 'rev-parse', 'HEAD'], text=True).strip()
    wrapper = ROOT / 'deployment/whatsapp_install_root_wrapper.sh'
    before = set(Path('/tmp').glob('fasakhansta-whatsapp-run.*'))
    result, output = terminal_run(wrapper, app, commit, SECRET)
    assert result == 0 and b'SECRET_HANDOFF_OK' in output, 'Secret handoff failed: ' + repr(output)
    result, output = terminal_run(wrapper, app, commit, 'g' * 32)
    assert result != 0 and b'SECRET_HANDOFF_OK' not in output, 'Invalid secret was passed to installer'
    assert set(Path('/tmp').glob('fasakhansta-whatsapp-run.*')) == before, 'Temporary scripts were retained'
    print('PASS: hidden input, real su session without /dev/tty, stdin handoff, invalid-input rejection, cleanup')
