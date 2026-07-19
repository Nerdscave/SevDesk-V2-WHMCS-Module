#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source_module_relative="modules/addons/sevdesk"
if [[ -n "$(git -C "${root}" ls-files --others --exclude-standard -- "${source_module_relative}")" ]]; then
    printf 'Release module contains untracked files. Stage the reviewed release files first.\n' >&2
    exit 2
fi
if ! git -C "${root}" diff --quiet -- "${source_module_relative}" docs/operations.md LICENSE; then
    printf 'Release sources contain unstaged changes. Stage the reviewed release files first.\n' >&2
    exit 2
fi
configured_version="$(git -C "${root}" show ":${source_module_relative}/sevdesk.php" \
    | sed -nE "s/^[[:space:]]*'version'[[:space:]]*=>[[:space:]]*'([^']+)'.*/\1/p")"
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

# The Git index is the release allowlist. Untracked or ignored files are never
# copied, even if they have an otherwise allowed extension below a module
# directory. This also makes the archive match the reviewed staged snapshot.
tracked_count=0
while IFS= read -r -d '' path; do
    relative="${path#${source_module_relative}/}"
    case "${relative}" in
        sevdesk.php|hooks.php|client_document.tpl|UPGRADE.md) ;;
        assets/*|cli/*|lib/*|templates/*|lang/*|theme-adapters/*) ;;
        *)
            printf 'Unexpected tracked module path: %s\n' "${path}" >&2
            exit 1
            ;;
    esac
    case "${path}" in
        *.php|*.tpl|*.css|*.js|*.json|*.md) ;;
        *)
            printf 'Unexpected tracked release file: %s\n' "${path}" >&2
            exit 1
            ;;
    esac
    destination="${target}/${path}"
    mkdir -p "$(dirname "${destination}")"
    git -C "${root}" show ":${path}" > "${destination}"
    tracked_count=$((tracked_count + 1))
done < <(git -C "${root}" ls-files -z --cached -- "${source_module_relative}")
if [[ "${tracked_count}" -lt 1 ]]; then
    printf 'No tracked sevdesk module files found in the Git index.\n' >&2
    exit 1
fi

git -C "${root}" show ':docs/operations.md' > "${target}/modules/addons/sevdesk/OPERATIONS.md"
git -C "${root}" show ':LICENSE' > "${target}/LICENSE"

unexpected="$(find "${target}" -type f ! \( \
    -name '*.php' -o -name '*.tpl' -o -name '*.css' -o -name '*.js' -o -name '*.json' -o -name '*.md' \
    -o -name 'LICENSE' \
\) -print)"
if [[ -n "${unexpected}" ]]; then
    printf 'Unexpected release file(s):\n%s\n' "${unexpected}" >&2
    exit 1
fi

# COPYFILE_DISABLE prevents AppleDouble entries on macOS. USTAR is supported
# by bsdtar and GNU tar and cannot serialize platform-specific PAX xattrs.
COPYFILE_DISABLE=1 tar --format=ustar -C "${target}" -czf "${root}/dist/sevdesk-${version}.tar.gz" LICENSE modules
printf '%s\n' "${root}/dist/sevdesk-${version}.tar.gz"
