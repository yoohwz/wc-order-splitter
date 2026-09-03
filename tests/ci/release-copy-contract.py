#!/usr/bin/env python3
"""Focused release-copy regressions, not a replacement for WordPress's parser."""
from pathlib import Path
import re
import sys
import unittest
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[2]
PRODUCT_URL = 'https://yoohw.com/product/woocommerce-advanced-order-actions/'
SECTIONS = ['Description', 'Installation', 'Frequently Asked Questions', 'Changelog', 'Upgrade Notice']
VERSION_HEADING = r'^= ([0-9]+\.[0-9]+\.[0-9]+)(?: \([^\n]*\))? =$'


def require(condition, message):
    if not condition:
        raise ValueError(message)


def validate(readme, history, plugin):
    require(len(readme.encode('utf-8')) < 10000, 'readme must remain below 10 KB')
    require(readme.startswith('=== Order Splitter for WooCommerce ===\n'), 'readme title is invalid')
    require('\r' not in readme and '\x00' not in readme, 'readme must use plain UTF-8 text with LF lines')
    require(re.findall(r'^== (.+) ==$', readme, re.M) == SECTIONS, 'readme sections/order are invalid')
    for line in readme.splitlines():
        if line.startswith('='):
            require(re.fullmatch(r'(={1,3}) [^=\n]+ \1', line), 'malformed readme heading')
    header, short, _ = readme.split('\n\n', 2)
    require(0 < len(short) <= 150 and '\n' not in short, 'short description must be one line and <=150 characters')
    require(not re.search(r'[*_`<>\[\]]', short), 'short description must not contain markup')
    for intent in ('woocommerce', 'split', 'quantity', 'category', 'stock status', 'duplicate', 'merge', 'return', 'fulfillment', 'hpos'):
        require(intent in short.lower(), 'short description lost search intent: ' + intent)
    for key, value in {
        'Contributors': 'yoohw', 'Requires at least': '6.5', 'Tested up to': '7.1',
        'WC tested up to': '11.0', 'Requires PHP': '7.4', 'Stable tag': '1.5.0',
        'License': 'GPLv3 or later', 'License URI': 'https://www.gnu.org/licenses/gpl-3.0.html',
    }.items():
        require(re.findall(r'^' + re.escape(key) + r': (.*)$', header, re.M) == [value], 'header mismatch: ' + key)
    require(re.findall(r'^ \* Version: (.*)$', plugin, re.M) == ['1.5.0'], 'plugin version must be 1.5.0')
    tag_headers = re.findall(r'^Tags: (.*)$', header, re.M)
    require(len(tag_headers) == 1, 'Tags header must be unique')
    tags = [tag.strip().lower() for tag in tag_headers[0].split(',')]
    require(1 <= len(tags) <= 5 and all(tags) and len(set(tags)) == len(tags), 'readme needs 1-5 distinct nonempty tags')
    changelog = readme.split('== Changelog ==\n', 1)[1].split('== Upgrade Notice ==', 1)[0]
    require(re.findall(r'^= (.+) =$', changelog, re.M) == ['1.5.0'], 'readme changelog must contain only 1.5.0')
    require('`changelog.txt`' in changelog, 'readme must point to full changelog history')
    versions = re.findall(VERSION_HEADING, history, re.M)
    require(versions[:2] == ['1.5.0', '1.4.11'], 'full history must start 1.5.0 then 1.4.11')
    require(len(versions) == len(set(versions)), 'full history has duplicate releases')
    require(not re.search(r'\b1\.4\.(?:12|13|14|15)\b', readme + history), 'unpublished versions entered public copy')
    latest = history.split('= 1.5.0 =', 1)[-1].split('= 1.4.11', 1)[0]
    public_copy = readme + '\n' + latest
    require(not re.search(r'WOS-[A-Z]+-\d+|PRODUCT_TREE_SHA|RELEASE_CERT|HUMAN_GATE|TASK_READY|\b[0-9a-f]{40,64}\b', public_copy), 'internal authority entered public copy')
    for stale in (
        'new Pending payment child orders',
        'Split child orders and Duplicate targets are created as Pending payment',
        'Shipping and positive fees remain on the source.',
        'The workflow rejects ambiguous state such as coupons, refunds or partial refunds',
        'Paid or transaction-bearing orders, refunds, coupons, fees, source-owned shipping',
        'Return is available only for a child whose original can be authenticated from hardened Split lineage',
        'an ineligible participant blocks confirmation of the batch',
    ):
        require(stale.lower() not in public_copy.lower(), 'stale runtime claim: ' + stale)
    require('= Upgrade to Advanced Order Actions =' in readme, 'dedicated Premium section is missing')
    links = re.findall(r'\]\((https?://[^\s)]+)\)', readme)
    require(PRODUCT_URL in links, 'canonical standalone Premium product link is missing')
    for link in links:
        parsed = urlsplit(link)
        require(not parsed.query and not parsed.fragment, 'public links must not carry tracking/query parameters')
        if 'woocommerce-advanced-order-actions' in parsed.path:
            require(link == PRODUCT_URL, 'Premium link must use canonical product page')
    return len(readme.encode('utf-8')), len(short), len(tags)


class ReleaseCopy(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.readme = (ROOT / 'readme.txt').read_text()
        cls.history = (ROOT / 'changelog.txt').read_text()
        cls.plugin = (ROOT / 'wc-order-splitter.php').read_text()

    def rejected(self, old, new, message, field='readme'):
        values = {key: getattr(self, key) for key in ('readme', 'history', 'plugin')}
        self.assertIn(old, values[field])
        values[field] = values[field].replace(old, new, 1)
        with self.assertRaisesRegex(ValueError, message):
            validate(**values)

    def test_current_copy(self):
        validate(self.readme, self.history, self.plugin)

    def test_short_description_bounds_and_intents(self):
        short = self.readme.split('\n\n')[1]
        for invalid, message in ((short + 'x' * 150, '150 characters'), (short.replace('HPOS', 'storage'), 'search intent'), ('**' + short + '**', 'markup')):
            with self.subTest(invalid=invalid):
                self.rejected(short, invalid, message)

    def test_size_tags_metadata_and_sections(self):
        self.rejected('== Description ==', 'x' * 10000 + '\n== Description ==', 'below 10 KB')
        self.rejected('merge orders\n', 'merge orders, fulfillment\n', '1-5 distinct')
        self.rejected('merge orders\n', 'split order\n', '1-5 distinct')
        self.rejected('Stable tag: 1.5.0', 'Stable tag: 1.4.11', 'header mismatch')
        self.rejected('Version: 1.5.0', 'Version: 1.4.11', 'plugin version', 'plugin')
        self.rejected('== Installation ==', '== Description ==', 'sections/order')
        self.rejected('= Split methods =', '= Split methods ==', 'malformed')

    def test_changelog_topology_and_history(self):
        self.rejected('== Upgrade Notice ==', '= 1.4.11 =\n\n== Upgrade Notice ==', 'only 1.5.0')
        self.rejected('`changelog.txt`', 'the archive', 'point to full')
        self.rejected('= 1.4.11 (Jun 13, 2026) =', '= 1.4.10 =', '1.5.0 then 1.4.11', 'history')
        self.rejected('= 1.4.10 (Apr 24, 2026) =', '= 1.4.15 =', 'unpublished', 'history')
        self.rejected('= 1.4.10 (Apr 24, 2026) =', '= 1.4.11 =', 'duplicate releases', 'history')

    def test_public_link_and_internal_authority(self):
        self.rejected(PRODUCT_URL, PRODUCT_URL + '?utm_source=free', 'canonical')
        self.rejected('Full public release history', '[More](' + PRODUCT_URL + '?ref=free) Full public release history', 'tracking/query')
        self.rejected('Full public release history', 'WOS-REL-001 Full public release history', 'internal authority')
        self.rejected('= Upgrade to Advanced Order Actions =', '= Premium =', 'dedicated Premium')

    def test_stale_claims(self):
        for stale in ('new Pending payment child orders', 'an ineligible participant blocks confirmation of the batch'):
            with self.subTest(stale=stale):
                self.rejected('Full public release history', stale + '\nFull public release history', 'stale runtime')


if __name__ == '__main__':
    if sys.argv[1:] == ['--self-test']:
        unittest.main(argv=[sys.argv[0]])
    elif len(sys.argv) == 2:
        root = Path(sys.argv[1])
        try:
            size, short_length, tag_count = validate(*((root / name).read_text(encoding='utf-8') for name in ('readme.txt', 'changelog.txt', 'wc-order-splitter.php')))
        except (ValueError, OSError) as error:
            sys.exit('release-copy-error: ' + str(error))
        print(f'release-copy-ok bytes={size} short_description={short_length} tags={tag_count}')
    else:
        sys.exit('usage: release-copy-contract.py STAGED_ROOT | --self-test')
