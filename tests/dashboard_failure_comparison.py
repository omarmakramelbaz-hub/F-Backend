"""Compare normalized PHPUnit failures without turning an existing red suite green."""
import argparse
from collections import Counter
import json
from pathlib import Path
import re
import xml.etree.ElementTree as ET


def collect(report, exit_code):
    root = ET.parse(report).getroot()
    signatures = []
    tests = list(root.iter('testcase'))
    for case in tests:
        for issue in case:
            if issue.tag not in ('error', 'failure'):
                continue
            lines = []
            for line in (issue.text or '').splitlines():
                line = line.strip()
                if re.match(r'^(?:/|[A-Za-z]:[\\/]).*:\d+', line):
                    break
                if line and line != (case.get('class', case.get('classname', '')) + '::' + case.get('name', '')):
                    lines.append(line)
            signatures.append({
                'test': case.get('class', case.get('classname', '')) + '::' + case.get('name', ''),
                'kind': issue.tag,
                'type': issue.get('type', ''),
                'message': issue.get('message') or '\n'.join(lines),
            })
    if not tests or (exit_code != 0 and not signatures):
        raise ValueError('PHPUNIT_REPORT_MISSING_RESULTS_OR_FAILURE_DETAILS')
    return {'tests': len(tests), 'exit_code': exit_code, 'issues': signatures}


def compare(directory):
    result = []
    for engine in ('sqlite', 'mysql'):
        before = json.loads((directory / ('baseline-' + engine + '.json')).read_text())
        after = json.loads((directory / ('candidate-' + engine + '.json')).read_text())
        key = lambda issue: json.dumps(issue, sort_keys=True)
        old = Counter(map(key, before['issues']))
        new = Counter(map(key, after['issues']))
        added = list((new - old).elements())
        result.append({'engine': engine, 'baseline_tests': before['tests'],
            'candidate_tests': after['tests'], 'baseline_failures': len(before['issues']),
            'candidate_failures': len(after['issues']), 'new_failure_count': len(added),
            'no_new_failures': not added and before['tests'] == after['tests']})
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)
    capture = sub.add_parser('collect')
    capture.add_argument('report', type=Path)
    capture.add_argument('exit_code', type=int)
    capture.add_argument('output', type=Path)
    comparison = sub.add_parser('compare')
    comparison.add_argument('directory', type=Path)
    args = parser.parse_args()
    if args.command == 'collect':
        args.output.write_text(json.dumps(collect(args.report, args.exit_code), indent=2) + '\n')
        return
    results = compare(args.directory)
    print(json.dumps(results, indent=2))
    raise SystemExit(0 if all(item['no_new_failures'] for item in results) else 1)


if __name__ == '__main__':
    main()
