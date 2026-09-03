"""One exact WOS policy for the pinned Plugin Check 2.1.0 release path.

Authority: WOS-REL-005, Issue #147 comment 5525485308. This classifies raw
findings; it never changes their upstream severity or suppresses their output.
"""
import release_package as pkg

POLICY_ID = 'WOS-REL-005-Plugin-Check-2.1.0'
ACCEPTED_ERROR_CODE = 'WordPress.Security.EscapeOutput.ExceptionNotEscaped'


def validate_report(report):
    pkg.require(isinstance(report, list) and all(isinstance(item, dict) and
                all(isinstance(item.get(key), str) for key in ('type', 'code', 'file', 'message')) and
                all(key not in item or isinstance(item[key], str) or type(item[key]) is int
                    for key in ('line', 'column')) and
                ('docs' not in item or isinstance(item['docs'], str)) for item in report),
                'invalid Plugin Check report')
    pkg.require(all(item['type'].upper() in {'WARNING', 'ERROR'} for item in report),
                'unexpected Plugin Check result type')


def classification(item):
    if item['type'].upper() == 'WARNING':
        return 'warning'
    # No normalization of codes, location baseline, prefix match, or override.
    return 'policy_accepted' if item['code'] == ACCEPTED_ERROR_CODE else 'blocking'


def counts(report):
    validate_report(report)
    accepted = sum(classification(item) == 'policy_accepted' for item in report)
    blocking = sum(classification(item) == 'blocking' for item in report)
    return {'raw_error_count': accepted + blocking,
            'policy_accepted_error_count': accepted,
            'blocking_error_count': blocking,
            'warning_count': len(report) - accepted - blocking}


def require_pass(report):
    pkg.require(counts(report)['blocking_error_count'] == 0,
                'Plugin Check blocking Errors block preparation; exact WOS policy only')
