# Patches

## @nextcloud/vue

The modal focus trap must not activate after the modal closes or unmounts
during its opening transition. The patch checks the modal state and element
after the asynchronous tick. The real-modal integration tests cover both
early closure and normal focus activation.

## @nextcloud/app-calendar

After installing the npm dependencies, regenerate the patch manually with:

``` shell
make fabricate-app-calendar-patch
# Or, from any working directory:
/path/to/cafevdb/dev-scripts/fabricate-app-calendar-patch.sh
```

The script applies the changes below to the installed package and invokes
the local `patch-package` executable to write its versioned patch in `patches/`.
Only files under the package's `src/` directory are included; test directories
(`test`, `tests`, `__tests__`) and `*.test.*` / `*.spec.*` files are excluded,
including any pre-existing test modifications in the installed package.
Alias replacements climb from the importing file's directory to the package
root, then descend through `src/`, matching the 6.6.2 patch: for example,
`src/appointments/main-booking.js` imports `../../src/views/Appointments/Booking.vue`,
and files directly under `src/` use `../src/`. The filename itself must not
count as another directory level.
It can be run again on an already transformed package, also correcting existing
`../.../src/` imports with an incorrect number of parent-directory segments.
Run the generator regression tests with
`node --test tests/scripts/fabricate-app-calendar-patch.test.mjs`.
The script invokes
`patch-package` with an empty temporary `--patch-dir` under `build/`, using
a relative path as required by `patch-package`, so existing patches
cannot be applied to the clean package used for comparison. After successful
generation, the new patch is moved into `patches/` and obsolete
`@nextcloud+app-calendar+*.patch` files are removed. If generation fails or
does not produce exactly one Calendar patch, existing patches remain unchanged.
Patches for other packages are left untouched. The script creates a local Git
commit containing only the generated Calendar patch and obsolete Calendar
patch deletions, leaving unrelated staged changes out of that commit.
The commit uses `-n` to bypass hooks because patch context intentionally
contains trailing whitespace.
An unchanged patch does not create another commit. Git commit failures are
reported; the generated patch remains available for inspection and retry.
Review the generated commit before pushing.

Already a patched version with some add-ons. In order to make it fit
for reusal in this app (we want to have the editor components) one has to
change some things:

- replace the path alias `@/` => `PACKAGE/src` by a relative path, e.g.:
``` shell
cd node_modules/@nextcloud/app-calendar/src
for i in $(grep -rFl "'@/"); do
  prefix="$(echo $i|sed  -E 's|[^/]+|..|g')/src/"
  sed -i "s|'@/|'$prefix|g" $i
done
```
