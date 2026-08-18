#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="1.4.12"
SLUG="wc-order-splitter"
BUILD_DIR="${ROOT_DIR}/build"
STAGE_PARENT="${BUILD_DIR}/stage"
STAGE_DIR="${STAGE_PARENT}/${SLUG}"
ZIP_PATH="${BUILD_DIR}/${SLUG}-${VERSION}.zip"
CHECKSUM_PATH="${ZIP_PATH}.sha256"
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-0}"

rm -rf "${BUILD_DIR}"
mkdir -p "${STAGE_DIR}"

copy_release_file() {
	local relative_path="$1"
	local source_path="${ROOT_DIR}/${relative_path}"
	local destination_path="${STAGE_DIR}/${relative_path}"

	if [[ ! -f "${source_path}" ]]; then
		echo "Required release file is missing: ${relative_path}" >&2
		exit 1
	fi

	mkdir -p "$(dirname "${destination_path}")"
	cp "${source_path}" "${destination_path}"
}

release_files=(
	"wc-order-splitter.php"
	"readme.txt"
	"changelog.txt"
	"license.txt"
	"css/style.css"
	"inc/cores/script.php"
	"inc/cores/safety.php"
	"inc/cores/settings-section-guard.php"
	"inc/backend/settings.php"
	"inc/backend/orders.php"
	"inc/backend/yoohw-woo-settings-tabs-reorder.php"
)

for relative_path in "${release_files[@]}"; do
	copy_release_file "${relative_path}"
done

php "${ROOT_DIR}/tests/release-contract.php"

while IFS= read -r -d '' php_file; do
	php -l "${php_file}" >/dev/null
done < <(find "${STAGE_DIR}" -type f -name '*.php' -print0)

if grep -R --line-number --fixed-strings 'yoexpress.top' "${STAGE_DIR}"; then
	echo 'Removed external subscription endpoint is present in the release stage.' >&2
	exit 1
fi

if grep -R --line-number -E 'wp_ajax_split_order|yoos_merge_order|yoos_handle_bulk_action|woocommerce_order_action_yoos_duplicate_order' "${STAGE_DIR}"; then
	echo 'A legacy mutation hook is present in the release stage.' >&2
	exit 1
fi

grep -Fq 'Version: 1.4.12' "${STAGE_DIR}/wc-order-splitter.php"
grep -Fq "define('WC_ORDER_SPLITTER_MUTATIONS_ENABLED', false);" "${STAGE_DIR}/wc-order-splitter.php"
grep -Fq 'Stable tag: 1.4.12' "${STAGE_DIR}/readme.txt"

if [[ "${SOURCE_DATE_EPOCH}" =~ ^[0-9]+$ ]] && [[ "${SOURCE_DATE_EPOCH}" -gt 0 ]]; then
	while IFS= read -r -d '' staged_path; do
		touch -d "@${SOURCE_DATE_EPOCH}" "${staged_path}"
	done < <(find "${STAGE_DIR}" -print0)
fi

(
	cd "${STAGE_PARENT}"
	find "${SLUG}" -type f -print | LC_ALL=C sort | zip -X -q "${ZIP_PATH}" -@
)

if command -v sha256sum >/dev/null 2>&1; then
	(
		cd "${BUILD_DIR}"
		sha256sum "$(basename "${ZIP_PATH}")" > "$(basename "${CHECKSUM_PATH}")"
	)
else
	(
		cd "${BUILD_DIR}"
		shasum -a 256 "$(basename "${ZIP_PATH}")" > "$(basename "${CHECKSUM_PATH}")"
	)
fi

zip_listing="$(unzip -Z1 "${ZIP_PATH}")"

for expected_file in "${release_files[@]}"; do
	if ! grep -Fxq "${SLUG}/${expected_file}" <<<"${zip_listing}"; then
		echo "Release ZIP is missing allowlisted file: ${expected_file}" >&2
		exit 1
	fi
done

if grep -E '(^|/)(tests|bin|build|\.git|\.github)/' <<<"${zip_listing}"; then
	echo 'Development files are present in the release ZIP.' >&2
	exit 1
fi

actual_count="$(grep -c . <<<"${zip_listing}")"
expected_count="${#release_files[@]}"

if [[ "${actual_count}" -ne "${expected_count}" ]]; then
	echo "Release ZIP contains ${actual_count} files; expected exactly ${expected_count}." >&2
	printf '%s\n' "${zip_listing}" >&2
	exit 1
fi

printf 'Built %s\n' "${ZIP_PATH}"
printf 'Checksum %s\n' "${CHECKSUM_PATH}"
