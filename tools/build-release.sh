#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source_module="${root}/modules/addons/sevdesk"
configured_version="$(sed -nE "s/^[[:space:]]*'version'[[:space:]]*=>[[:space:]]*'([^']+)'.*/\1/p" "${source_module}/sevdesk.php")"
if [[ -z "${configured_version}" || "${configured_version}" == *$'\n'* ]]; then
    printf 'Could not determine one module version from sevdesk.php.\n' >&2
    exit 2
fi
version="${1:-${configured_version}}"
if [[ ! "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?$ ]]; then
    printf 'Version must be a SemVer value such as 2.0.0 or 2.0.0-rc.1.\n' >&2
    exit 2
fi
if [[ "${version}" != "${configured_version}" ]]; then
    printf 'Requested version %s does not match module version %s.\n' "${version}" "${configured_version}" >&2
    exit 2
fi
target="${root}/dist/sevdesk-${version}"

# Keep the destructive cleanup mechanically confined to the ignored dist tree.
case "${target}" in
    "${root}/dist/sevdesk-"*) ;;
    *)
        printf 'Refusing release target outside dist/.\n' >&2
        exit 2
        ;;
esac

rm -rf "${target}"
mkdir -p "${target}/modules/addons/sevdesk"

# Deliberate allowlist: private evidence, repository tooling, tests, Composer
# dependencies and any accidental root-level file cannot enter the archive.
cp "${source_module}/sevdesk.php" "${source_module}/hooks.php" "${target}/modules/addons/sevdesk/"
cp "${source_module}/client_document.tpl" "${source_module}/UPGRADE.md" "${target}/modules/addons/sevdesk/"
cp "${root}/docs/operations.md" "${target}/modules/addons/sevdesk/OPERATIONS.md"
cp "${root}/LICENSE" "${target}/LICENSE"
for directory in assets cli lib templates lang theme-adapters; do
    if [[ -d "${source_module}/${directory}" ]]; then
        cp -R "${source_module}/${directory}" "${target}/modules/addons/sevdesk/${directory}"
    fi
done

unexpected="$(find "${target}" -type f ! \( \
    -name '*.php' -o -name '*.tpl' -o -name '*.css' -o -name '*.js' -o -name '*.json' -o -name '*.md' \
    -o -name 'LICENSE' \
\) -print)"
if [[ -n "${unexpected}" ]]; then
    printf 'Unexpected release file(s):\n%s\n' "${unexpected}" >&2
    exit 1
fi

tar -C "${target}" -czf "${root}/dist/sevdesk-${version}.tar.gz" LICENSE modules
printf '%s\n' "${root}/dist/sevdesk-${version}.tar.gz"
