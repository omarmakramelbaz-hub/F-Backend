#!/usr/bin/env python3
"""Extract only the reviewed private eSpeak library/data; never install Python packages."""
import argparse
import importlib.util
from pathlib import Path
import tempfile
import urllib.request

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location('go_arabic_deploy', HERE / 'install_root.py')
DEPLOY = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(DEPLOY)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument('--archive', type=Path)
    source.add_argument('--download', action='store_true')
    parser.add_argument('--destination', required=True, type=Path)
    args = parser.parse_args()
    target = args.destination.resolve()
    if target.exists():
        parser.error('destination must not exist')
    with tempfile.TemporaryDirectory(prefix='go-arabic-engine-') as temporary:
        archive = args.archive
        if args.download:
            archive = Path(temporary) / 'engine.whl'
            with urllib.request.urlopen(DEPLOY.ENGINE_URL, timeout=60) as response, archive.open('xb') as output:
                total = 0
                while True:
                    chunk = response.read(65536)
                    if not chunk:
                        break
                    total += len(chunk)
                    if total > DEPLOY.ENGINE_SIZE:
                        raise RuntimeError('engine download too large')
                    output.write(chunk)
        files = DEPLOY.engine_files(archive)
        target.mkdir(mode=0o750, parents=True)
        for relative, data in files.items():
            path = target / relative
            path.parent.mkdir(mode=0o750, parents=True, exist_ok=True)
            path.write_bytes(data)
            path.chmod(0o640)
    print('GO_ARABIC_ENGINE_VERIFIED files=' + str(len(files)) + ' sha256=' + DEPLOY.ENGINE_SHA256)


if __name__ == '__main__':
    main()
