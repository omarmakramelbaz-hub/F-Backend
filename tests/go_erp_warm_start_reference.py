"""Load unchanged .50 release fixtures from the exact pinned Git object."""
import atexit
import io
from pathlib import Path
import subprocess
import tarfile
import tempfile
import sys

ROOT=Path(__file__).resolve().parents[1]
PREVIOUS='baa2157fea6f943c55597c924447cc574d9fcef2'
PRIOR='2d9e23a5157782882382c797a44ad15c317ae845'
for revision in (PREVIOUS,PRIOR):
    subprocess.run(['git','cat-file','-e',revision+'^{commit}'],cwd=ROOT,check=True,capture_output=True)
_temporary=tempfile.TemporaryDirectory(prefix='go-erp-warm-start-prior-')
atexit.register(_temporary.cleanup)
REFERENCE=Path(_temporary.name)
raw=subprocess.check_output(['git','archive',PREVIOUS,'deployment','tests','.github'],cwd=ROOT)
with tarfile.open(fileobj=io.BytesIO(raw)) as archive:
    archive.extractall(REFERENCE,filter='data')
_git=subprocess.check_output(['git','rev-parse','--absolute-git-dir'],cwd=ROOT,text=True).strip()
(REFERENCE/'.git').write_text('gitdir: '+_git+'\n')
sys.path.insert(0,str(REFERENCE/'tests'))
