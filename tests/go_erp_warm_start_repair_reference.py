"""Load the reviewed R1 repair predecessor from its immutable Git object."""
import atexit
import io
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile

ROOT=Path(__file__).resolve().parents[1]
PREVIOUS='12d74b4f35737a2b1e271657b159137538c3edaf'
subprocess.run(['git','cat-file','-e',PREVIOUS+'^{commit}'],cwd=ROOT,check=True,capture_output=True)
_temporary=tempfile.TemporaryDirectory(prefix='go-erp-warm-start-repair-reference-')
atexit.register(_temporary.cleanup)
REFERENCE=Path(_temporary.name)
raw=subprocess.check_output(['git','archive',PREVIOUS,'deployment','tests','.github'],cwd=ROOT)
with tarfile.open(fileobj=io.BytesIO(raw)) as archive:
    archive.extractall(REFERENCE,filter='data')
_git=subprocess.check_output(['git','rev-parse','--absolute-git-dir'],cwd=ROOT,text=True).strip()
(REFERENCE/'.git').write_text('gitdir: '+_git+'\n')
sys.path.insert(0,str(REFERENCE/'tests'))
