#!/usr/bin/env python3
"""Apply the same trusted WOS policy locally; no GitHub/release credentials needed."""
from pathlib import Path
import sys

sys.dont_write_bytecode = True
import release_cli as cli

if __name__ == '__main__':
    try:
        if len(sys.argv) != 3:
            raise ValueError('usage: local-plugin-check-report.py RAW_REPORT DIAGNOSTICS_JSON')
        raw, diagnostics = map(Path, sys.argv[1:])
        if raw.resolve() == diagnostics.resolve():
            raise ValueError('raw report and diagnostics must be separate files')
        cli.plugin_check_report(raw.read_text() if raw.is_file() else '', diagnostics)
    except (ValueError, OSError) as error:
        sys.exit('local-plugin-check-error: ' + str(error))
