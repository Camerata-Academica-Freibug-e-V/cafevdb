#!/bin/bash
# SPDX-License-Identifier: AGPL-3.0-or-later

set -euo pipefail

if [[ $# -ne 0 ]]; then
    echo "Usage: $0" >&2
    exit 1
fi

cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.."

if [[ ! -d node_modules/@nextcloud/app-calendar/src || ! -x node_modules/.bin/patch-package ]]; then
    echo "Install the app's npm dependencies before fabricating the calendar patch." >&2
    exit 1
fi

node --input-type=module <<'NODE'
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import path from 'node:path'

const packageRoot = path.resolve('node_modules/@nextcloud/app-calendar')
const source = path.join(packageRoot, 'src')
const changes = new Map()
const testPath = /(^|\/)(tests?|__tests__)(\/|$)|\.(spec|test)\.[^/]+$/

function transformDirectory(directory) {
    for (const entry of readdirSync(directory, { withFileTypes: true })) {
        const filename = path.join(directory, entry.name)
        if (testPath.test(path.relative(source, filename).split(path.sep).join('/'))) {
            continue
        }
        if (entry.isDirectory()) {
            transformDirectory(filename)
        } else if (entry.isFile()) {
            const buffer = readFileSync(filename)
            if (buffer.includes(0)) {
                continue
            }
            const original = buffer.toString('utf8')
            const prefix = path.relative(path.dirname(filename), packageRoot).split(path.sep).join('/')
            const updated = original.replace(/(['"])(?:@\/|(?:\.\.\/)+src\/)/g, `$1${prefix}/src/`)
            if (updated !== original) {
                changes.set(filename, updated)
            }
        }
    }
}

transformDirectory(source)

for (const [filename, contents] of changes) {
    writeFileSync(filename, contents)
}
NODE

shopt -s nullglob
mkdir -p -- build
patchDirectory="$(mktemp -d build/calendar-patches.XXXXXX)"

cleanup() {
    local status=$?
    local temporaryPatches=("$patchDirectory"/@nextcloud+app-calendar+*.patch)
    if [[ ${#temporaryPatches[@]} -gt 0 ]]; then
        rm -- "${temporaryPatches[@]}"
    fi
    rmdir -- "$patchDirectory"
    exit "$status"
}
trap cleanup EXIT

node_modules/.bin/patch-package @nextcloud/app-calendar --use-npm \
    --patch-dir "$patchDirectory" \
    --include '^src/' --exclude '(^|/)(tests?|__tests__)(/|$)|\.(spec|test)\.[^/]+$'

generatedPatches=("$patchDirectory"/@nextcloud+app-calendar+*.patch)
if [[ ${#generatedPatches[@]} -ne 1 ]]; then
    echo "Expected exactly one generated Calendar patch; existing patches have not been changed." >&2
    exit 1
fi

mkdir -p -- patches
generatedPatch="${generatedPatches[0]##*/}"
mv -- "${generatedPatches[0]}" "patches/$generatedPatch"
for patch in patches/@nextcloud+app-calendar+*.patch; do
    if [[ ${patch##*/} != "$generatedPatch" ]]; then
        rm -- "$patch"
    fi
done

calendarPatchPathspec=':(glob)patches/@nextcloud+app-calendar+*.patch'
git add -A -- "$calendarPatchPathspec"
if ! git diff --cached --quiet -- "$calendarPatchPathspec"; then
    git commit -n --only \
        -m "build: regenerate $generatedPatch" \
        -m "Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>" \
        -- "$calendarPatchPathspec"
fi
