#!/usr/bin/env python3
from __future__ import annotations
import argparse, hashlib, os, stat, zipfile
from pathlib import Path

def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    source = args.source.resolve()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.unlink(missing_ok=True)
    with zipfile.ZipFile(args.output, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(source.rglob('*')):
            if not path.is_file() or path.is_symlink():
                continue
            rel = path.relative_to(source).as_posix()
            info = zipfile.ZipInfo(f'yassin-ai-assistant/{rel}', (2000, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = ((stat.S_IFREG | 0o644) << 16)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.flag_bits |= 0x800
            archive.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    digest = hashlib.sha256(args.output.read_bytes()).hexdigest()
    print(f'{digest}  {args.output.name}')

if __name__ == '__main__':
    main()
