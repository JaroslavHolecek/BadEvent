#!/usr/bin/env python3
"""Generate create.sql from create.template.sql and meta-settings.json."""

import json
import re
import sys
from pathlib import Path

HERE = Path(__file__).parent

TEMPLATE = HERE / "create.template.sql"
SETTINGS = HERE / "meta-settings.json"
OUTPUT   = HERE / "create.generated.sql"


def main():
    with SETTINGS.open() as f:
        db = json.load(f)["db"]

    template = TEMPLATE.read_text()

    errors = []

    def substitute(match):
        marker = match.group(0)          # e.g. [contact_types.name]
        table, attr = match.group(1).split(".", 1)
        try:
            return str(db[table][attr])
        except KeyError:
            errors.append(f"  {marker}  (db.{table}.{attr})")
            return marker                # leave marker intact so output is still readable

    result = re.sub(r'\[([a-z_]+\.[a-z_]+)\]', substitute, template)

    if errors:
        print("ERROR: missing entries in meta-settings.json:", file=sys.stderr)
        for e in errors:
            print(e, file=sys.stderr)
        sys.exit(1)

    OUTPUT.write_text(result)
    print(f"Written: {OUTPUT}")


if __name__ == "__main__":
    main()