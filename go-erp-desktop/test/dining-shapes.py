"""The dashboard's dine-in and delivery answers still have the shapes the app answers with offline.
   python3 go-erp-desktop/test/dining-shapes.py fresh.json go-erp-desktop/test/fixtures/dining.json"""
import json
import sys


def keys(value, path=''):
    found = set()
    if isinstance(value, dict):
        for key, item in value.items():
            found.add(path + '.' + key)
            found |= keys(item, path + '.' + key)
    elif isinstance(value, list) and value:
        found |= keys(value[0], path + '[]')
    return found


fresh, kept = (json.load(open(name, encoding='utf-8')) for name in sys.argv[1:3])
assert len(fresh) == len(kept), f'{len(fresh)} answers recorded, the fixture has {len(kept)}'
for new, old in zip(fresh, kept):
    request = f"{old['method']} {old['path']}"
    assert new['status'] == old['status'], f'{request}: status {new["status"]} instead of {old["status"]}'
    added, removed = keys(new['json']) - keys(old['json']), keys(old['json']) - keys(new['json'])
    assert not added and not removed, f'{request}: the dashboard answer changed shape (new {sorted(added)}, gone {sorted(removed)}); update the offline answers and the fixture'
print(f'{len(kept)} dashboard answers keep their shape')
