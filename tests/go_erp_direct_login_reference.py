"""Load the exact R2 transaction fixture without depending on ambient rollout files."""
import atexit
import io
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[1]
PUBLIC = '0adf41c64aa587b495a55b7ec058bf5cc54d1e2a'
LOCAL = 'f159a78922a4689f0b697a7b0aa6bf879bef8929'
TREE = 'dffd28af272546ea8e28543d7771da6c3354a61d'
available = subprocess.run(['git', 'cat-file', '-e', PUBLIC + '^{commit}'], cwd=ROOT,
    stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
revision = PUBLIC if available.returncode == 0 else LOCAL
tree = subprocess.check_output(['git', 'rev-parse', revision + '^{tree}'], cwd=ROOT, text=True).strip()
if tree != TREE:
    raise RuntimeError('DIRECT_LOGIN_REFERENCE_TREE_CHANGED')
_temporary = tempfile.TemporaryDirectory(prefix='go-erp-direct-login-reference-')
atexit.register(_temporary.cleanup)
REFERENCE = Path(_temporary.name)
raw = subprocess.check_output(['git', 'archive', revision, 'deployment', 'tests', '.github'], cwd=ROOT)
with tarfile.open(fileobj=io.BytesIO(raw)) as archive:
    archive.extractall(REFERENCE, filter='data')
repository = subprocess.check_output(['git', 'rev-parse', '--absolute-git-dir'], cwd=ROOT, text=True).strip()
(REFERENCE / '.git').write_text('gitdir: ' + repository + '\n')
sys.path.insert(0, str(REFERENCE / 'tests'))
