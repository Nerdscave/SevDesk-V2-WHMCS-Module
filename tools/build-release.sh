#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version="${1:-2.0.0}"
if [[ ! "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?$ ]]; then
    printf 'Version must be a SemVer value such as 2.0.0 or 2.0.0-rc.1.\n' >&2
    exit 2
fi
target="${root}/dist/sevdesk-${version}"
source_module="${root}/modules/addons/sevdesk"

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
for directory in assets cli lib templates lang; do
    if [[ -d "${source_module}/${directory}" ]]; then
        cp -R "${source_module}/${directory}" "${target}/modules/addons/sevdesk/${directory}"
    fi
done

unexpected="$(find "${target}" -type f ! \( \
    -name '*.php' -o -name '*.tpl' -o -name '*.css' -o -name '*.js' -o -name '*.json' \
\) -print)"
if [[ -n "${unexpected}" ]]; then
    printf 'Unexpected release file(s):\n%s\n' "${unexpected}" >&2
    exit 1
fi

tar -C "${target}" -czf "${root}/dist/sevdesk-${version}.tar.gz" modules
printf '%s\n' "${root}/dist/sevdesk-${version}.tar.gz"
