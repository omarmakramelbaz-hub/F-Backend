#!/usr/bin/env python3
"""Check native GO SVG clip compatibility before publishing a Blade partial."""
import argparse
from pathlib import Path
from xml.etree import ElementTree as ET


def check(path):
    source = path.read_text()
    root = ET.fromstring(source[source.index('<svg'):])
    tag = lambda node: node.tag.rsplit('}', 1)[-1]
    nodes = [node for node in root.iter() if node.get('id')]
    ids = {node.get('id'): node for node in nodes}
    if len(ids) != len(nodes):
        raise ValueError('DUPLICATE_SVG_ID')
    shapes = {'path', 'text', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon'}
    for clip in root.iter('{http://www.w3.org/2000/svg}clipPath'):
        for child in clip:
            if tag(child) == 'use':
                target = ids.get(child.get('href', '').removeprefix('#'))
                if target is None or tag(target) not in shapes:
                    raise ValueError('INVALID_CLIP_USE_TARGET')
            elif tag(child) not in shapes:
                raise ValueError('INVALID_CLIP_CHILD')
    for node in root.iter('{http://www.w3.org/2000/svg}use'):
        if not node.get('href', '').startswith('#') or node.get('href')[1:] not in ids:
            raise ValueError('INVALID_LOCAL_SVG_REFERENCE')
    shape = next(node for node in nodes if node.get('id').endswith('-shape'))
    surface = next(node for node in nodes if node.get('id').endswith('-surface'))
    if [node.get('d') for node in shape] != [node.get('d') for node in surface]:
        raise ValueError('SURFACE_SILHOUETTE_CHANGED')
    if any(node.get('clip-rule') != 'evenodd' for node in surface):
        raise ValueError('SURFACE_COUNTERS_NOT_PRESERVED')
    rest = next(node for node in root if 'go-erp-logo-rest' in node.get('class', '').split())
    if rest.get('filter') or not any(tag(node) == 'use' for node in rest):
        raise ValueError('VISIBLE_BASE_LOGO_MISSING')
    print('GO_ERP_SVG_CLIPS_OK')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('partial', type=Path)
    args = parser.parse_args()
    try:
        check(args.partial)
    except (ValueError, StopIteration, ET.ParseError) as error:
        raise SystemExit('STOPPED: ' + str(error))
