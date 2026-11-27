CAFEVDB -- Orchestra Management App
===================================

The goal of the project is a group-ware application which aids
the management of a project-oriented orchestra. This stuff arose out
of the frustation about collections of unwieldy "Excel"-sheets and
other frustrations. The web-site of the concrete orchestra in question
is

http://www.cafev.de/

The current state of the affair is wrapped into a Nextcloud appliction.

Features include

- address management
- project management
  - selection of project participants
  - instrumentation numbers
  - custom per-participant per-project data like service fees, twin-rooms etc.
  - form letters with substitutions
- export of participation lists and other tables in office spread-sheet formats
- integration of a DokuWiki
- integration of web-pages, currently only the old ancient Redaxo4
- addressbook integration via Nextcloud
- encryption of sensible information
- management of bank accounts and debit mandates
- export of bulk-transactions in form suitable for input to AqBanking-CLI

The orchestra data-base is held separate from the cloud database and
is based on Doctrine/ORM.

Participant-field folders
-------------------------

Nextcloud rejects an upload that would replace an existing folder. If an
upload instead takes a still-unused name reserved for a participant-field
folder, CafeVDB attempts to rename the file in the same directory after the
upload finishes. The uploader gets a Nextcloud notification with the new
path. If the rename fails, the file is left in place and the notification
asks the uploader to move or rename it manually. No participant-field value
or README is created for the conflicting file.

Auditing exported personalized database storage views
-----------------------------------------------------

Personalized directory-entry views export field documents only when the exact
entry is referenced by a field datum for that storage's musician and project,
and the field permits `read` or `read-write` participant access. This applies to
supporting documents and `db-file` values. Unreferenced non-field entries retain
the ownership and participant-tree checks; a readable link to the same encrypted
file does not expose a hidden field entry. Folders without visible descendants
are omitted. Management-board storage is unchanged and retains hidden field
documents. This filters filesystem entries, not direct ownership-based access
through the personalized `Files` and `FileData` views.
After deploying this change, use **Recreate personalized views** in the sharing
settings to apply the updated definitions to an existing connector database.

If debit-mandate synchronization fails, its previously cached finance mount is
made unavailable while other mounts remain available when the database and
transaction are healthy. Without a trusted cached mandate mount, or if the
database cannot safely continue, the existing all-mount fallback applies.

To inspect an existing orchestra database before changing personalized storage
views, run `occ cafevdb:storage:database --audit-access`. This authenticates with
the usual interactive CafeVDB operator login. The audit itself only reads
metadata; it does not decrypt file contents, repair links, flush ORM entities,
or regenerate views. Use `--json` for a report with `summary` and `findings`;
exit status is 0 for no access findings, 1 for inconsistencies, and 2 for
invalid option combinations.
Database/query failures are errors, never a clean audit.

Run `occ cafevdb:storage:database --audit-encryption` separately to inspect
encryption access. Its text output lists issues only; `--json` includes a
summary and `encryption_diagnostics` for each encrypted-file owner and each
audit-approved missing owner, including healthy entries. Its exit status depends
only on encryption issues, not structural access findings. It checks the seal's
recipient IDs without decrypting the payload and reports whether the Nextcloud user exists,
has logged in, and has stored public and private key material. The report
includes the user ID but never the seal keys or file contents. A missing user
ID or an unsealed, malformed or missing payload leaves recipient status
unknown. Key presence and a matching recipient are indicators, not proof that
the keys match or the file can be decrypted. The login indicator is based on
Nextcloud's recorded last login, not proof that a key was generated.
Once the affected user has logged in and obtained their key pair, reconcile
missing seal recipients manually with `occ cafevdb:users:recrypt --user=USER`.
The audit does not change ownership, keys or seals.

To list directory-entry trees not connected to a registered `DatabaseStorage`,
run `occ cafevdb:storage:database --find-orphan-trees`, optionally with `--json`.
The read-only listing groups entry IDs, parent IDs, file IDs and types by root
ID, including empty trees. Add `--include-file-names` to include entry names and
`parent_path` relative to the orphan tree (root parents use `/`); entries are
sorted by `parent_path`, then `name`, and trees by root name.
Use `--find-orphan-trees=prune-empty` to remove complete trees containing only
folders. Trees containing file entries, file references, or nested registered
storages are preserved. Pruning owns a transaction, locks the relevant metadata,
deletes leaves before parents, and rolls back on errors. Its report lists the
remaining trees and `pruned_root_ids`, with pruned tree/entry counts. Run pruning
during a quiet maintenance window; repeating it is safe.
The included names and paths
these may identify people and should be kept private. These trees have no known
mount path. Registered roots and their descendants are excluded, including
registered subtrees nested beneath an unregistered root. This lists trees with
a parentless root, not broken parent chains or cycles. It does not infer that
their files are unused: business references and links elsewhere may still
exist. The listing does not remove entries, files or directories, and cannot be
combined with `--remove-orphans`, `--find-orphans`, `--list-storages` or
either audit option. Listing succeeds even when trees are found; query failures
remain errors.

After reviewing the audit, an orchestra admin can run
`occ cafevdb:storage:database --repair-access --lost+found` to repair
participant links and then collect remaining structurally valid orphan trees.
They appear under `<shared orchestra folder>/lost+found`, in UTC timestamped
folders with second precision and collision suffixes. Only members of the
configured orchestra-admin group receive this authenticated mount. Files remain
encrypted; mount access does not change their encryption recipients.

Administrators can delete unreferenced recovered files and folders. Deleting a
folder checks the entire subtree and either deletes it completely or leaves it
unchanged. Known references, including business documents, retained deleted
field values and registered roots, block deletion. File owners alone do not
block deletion; the encrypted file is removed when its last directory link is
deleted. The operating user receives a Nextcloud notification identifying the
blockers. Creation, editing and renaming are not supported.
Deletion uses a serializable transaction on MySQL/MariaDB and locks the
relevant metadata; as with repair, avoid concurrent maintenance operations.

Malformed trees, including non-folder parents, invalid file entries and nested
registered storage roots, are reported and left untouched. A nonzero exit
status also indicates rejected trees. Collection uses its own transaction
after repair: if it fails, already committed repairs remain in place. Use
`--dry-run` to preview repair and collection without changes; the collection
preview describes the **current** trees, before the proposed repairs. Run
during a quiet maintenance window.

The access audit still examines business references beneath lost+found, and
`--include-file-names` includes their recovery paths. Later repair runs may
move a recovered entry to its participant storage when all known references
justify the same destination; ambiguous entries stay in place.

The audit recognizes debit mandates, invoices, donation receipts, composite
payment supporting documents, participant `db-file` values and participant
field supporting documents (including generated insurance records). An
ownership grant or participant-storage link alone is not a business reference.
By default, findings use database identifiers rather than names, paths or document
contents; encryption diagnostics also include user IDs. Add `--include-file-names` to include `file_name` (the underlying file's
stored name) and, for findings with an `entry_id`, `entry_name` (the directory-entry
name, which may differ). Missing names are `null`. This works with text and JSON
output. The same option adds `entry_path`, the full participant mount path
(starting at the shared orchestra folder, without a Nextcloud user's `/files`
prefix). File-level findings without an `entry_id` include `file_paths`, listing
each directory entry and its full path. Paths use current folder configuration
and app-localized document folder names. Unresolved paths, including broken
directory chains or roots without a known participant mount, are `null`, never
misleading partial paths. Names and paths may identify people, so keep such
reports private.
Findings with associated participant fields include `participant_fields`, one
entry per datum/reference, with field ID, type, multiplicity, participant access,
relation (`supporting_document_id` or `option_value`), entry ID, musician,
project and option key. Associations follow all directory links to the same
file, so an association may belong to another project or musician. Only `db-file`
option values are treated as entry IDs. Use `--include-field-names` to include
the stored field name; it can identify people and should be kept private.
They include retained soft-deleted records, since ownership-based views do not
filter those records, and show each referenced record's `deleted` value.
Links to a club-members-project mandate in the same musician's other participant
storages are recognized using the configured club-members project ID.

Findings distinguish unrecognized owned files, unexpected owners, missing
ownership, missing participants or participant-tree links, unrelated-project
and non-owner participant links, broken document references and broken
directory trees. Shared files with explicit references for several participants
are allowed; dedicated finance links are not mistaken for participant links.
Review findings before attempting a backfill: this command intentionally does
not infer associations from filenames or directory names. Run during a quiet
maintenance window; it scans file/directory metadata and uses the database's
configured transaction isolation. It does not check deployed view definitions,
connector grants or Nextcloud's file cache.

Each finding has `repairable`; `true` identifies missing ownership supported by
an active, participant-readable supporting-document reference in that
participant's own storage and project, an active invoice, donation receipt or
composite-payment supporting document, or a debit mandate, including a revoked
mandate. Financial documents qualify only for their own musician and project, and only when a
link to the same encrypted file already exists in that participant's storage;
the financial document may use a separate directory entry. Financial documents
are not excluded solely because the participant's status is `associated`.
An invoice, donation receipt, composite-payment document or debit mandate may
also qualify when its participant link is missing:
the destination must be an existing, unambiguous participant-storage root in
the document's own project. A mandate's source entry must not be misplaced in
another participant's or project's storage. The proposed
steps include `add-participant-link`, and include `add-ownership` and
`reconcile-encryption-seal` when ownership is missing. The existing source
entry is preserved; a future repair must check destination names and conflicts.
Tax exemption notices do not qualify. A club-members-project mandate also
justifies links in the same musician's other active project storages; other
mandates justify only their own project.
The same missing-link classification applies to active participant-readable
supporting documents of participant fields, subject to their access and context
rules. Existing ownership alone does not establish that the seal includes the
participant or that the participant can decrypt it.
For active `db-file` participant fields, a missing participant-storage link may
qualify for `add-participant-link` alone, even when `participant_access` is `none`.
This restores the administrative storage link without granting ownership or
participant access. The destination must be valid and unambiguous, and broken
source trees are excluded.
If a financial link is also associated with a participant field, the
personalized view's field-access filter still applies; the suggested ownership
repair alone does not override it.
Participant-only fields must match the participant's context. Except for revoked
debit mandates, deleted records, invalid files and ambiguous links are not
candidates. Such findings include `repairsteps`:
`add-ownership` and `reconcile-encryption-seal`. These are proposed steps, not
repairs performed or proof that encryption keys and seals are usable. The audit
does not validate decryption.
Run `occ cafevdb:storage:database --repair-access --dry-run --json` to preview
the audit-approved metadata backfill, then omit `--dry-run` to apply it. The
audit action itself remains read-only. Run during a quiet maintenance window:
the executor owns a transaction and locks the audit's input rows before
classifying them. Unexpected errors roll back the transaction; filename and
one-to-one-reference conflicts are reported as `blocked` without replacing
entries. Independent ownership repairs may still proceed when a link conflicts.
The JSON report lists applied, planned, blocked and deferred operations and
includes the remaining audit findings. A blocked operation gives a failing exit
status; remaining findings and deferred seal work must still be reviewed.

New links use the existing entry's filename and the app's localized document
folders. Participant fields use their field folder path. An entry in a tree
without a storage is moved when all its references have one repair destination;
otherwise a participant link is created and the source entry is retained. The backfill never
creates missing storage roots, deletes source entries, resurrects soft-deleted
records, or grants ownership for a private field's link-only repair.
Participant-field, composite-payment and debit-mandate references are pointed
to their new participant entries; dedicated invoice/receipt entries are retained.
New and moved directory entries are persisted through the ORM so its cache-coherency
listeners record the corresponding changes. Business references are updated
directly to avoid ORM orphan removal deleting shared source entries; the command
clears the ORM identity map afterward.

A missing or unusable participant public key does not prevent recording
ownership. Seal reconciliation is explicitly deferred and reported, and no
encrypted payload is read or rewritten. The owner list and the recipients of
the currently stored seal can therefore differ. Repeating the operation creates
no duplicate ownership grants or links. A subsequent run may repair newly
eligible findings, such as club-mandate cross-project links after their canonical
source link has been restored.
`false` means this audit has not identified a safe automatic repair; it does not
mean that the inconsistency is irreparable. When known, `repair_reasons` lists
specific blockers, such as `musician-deleted`, `participant-storage-shared` or
`field-access-not-readable`. `club-mandate-source-link-missing` means that no
active club mandate for that file has its canonical entry in the musician's
club-project storage; other blockers may still apply. The reasons are not
exhaustive; their absence does not imply that a finding is safe to repair.

Database-backed files and Collabora
-----------------------------------

Database mounts require the CafeVDB app key to unlock encrypted database
configuration. On validated WOPI requests, CafeVDB can recover that key without
logging in to Nextcloud or unlocking the user's private key.
This currently supports local users whose editor and filesystem user are the
same, not guest or remote-user tokens.

Protect WOPI tokens in storage and transport: someone who obtains both a token
and its encrypted credential can recover the shared app key.

Connector databases
-------------------

The views must be on the orchestra database server, but Nextcloud itself may use
another database engine or server. The orchestra database account needs permission
to create the views and grant access.

Under **Members as Cloud-Users**, configure the username, account host and
password of the MariaDB account to which CafeVDB grants view access. The host is
the host part of the account (for example `localhost` or `%`), not the database
server address. CafeVDB checks the login and verifies enabled views, stores the
credentials encrypted with Nextcloud's system crypto, and shares them with the
members app. The password is write-only; leave it blank to retain the saved
password when the account is unchanged. Restoring the Nextcloud account clears
the shared credentials and prevents an older members-local password from being
reused. When replacing an account, revoke any grants it no longer needs.

With permission to manage `user_sql` settings, CafeVDB also configures its
database, server and credentials while preserving its safe-store and TLS
settings. `user_sql` stores its own password according to its safe-store setting;
enable safe-store in `user_sql` if it should use Nextcloud's system config.
The installed `user_sql` inherits the port or socket from Nextcloud's database
configuration. If that does not match the orchestra server, CafeVDB leaves the
`user_sql` connection unchanged and reports a warning.
Recreating cloud-user views is available to orchestra sub-admins. If CafeVDB
cannot update `user_sql` settings afterwards, the views remain in place and a
warning asks for the settings to be checked; access may require delegated
`user_sql` settings rights or a Nextcloud administrator.

CafeVDB publishes `cloudConnectorDescriptor` as read-only, non-secret app
configuration for consumer apps. Version 1 contains `database` (the effective
views schema, falling back to the orchestra schema), `account` (`user` and
`accountHost`), `personalizedViewsEnabled`, and a content-hash `revision`.
It contains no password or orchestra connection credentials and remains readable
without the orchestra app key. Saving the account, database or personalized-view
setting refreshes it; opening orchestra settings initializes it on upgrades.
Consumers should read it on each request, not maintain another synchronized copy.
The separate `cloudConnectorCredentials` value is encrypted with Nextcloud's
system crypto and is not included in the descriptor or settings responses.
Members can inherit the schema while retaining an explicit local override and
its own connection/access checks. Changing schemas still removes the old views;
coordinate such changes with active consumers.

For **WOPI access to DB-Storage**, configure a separate restricted account and
its password. CafeVDB needs these credentials before its app key is unlocked.
The fields are write-only, and CafeVDB encrypts the credentials with Nextcloud's
system crypto. Saving checks the login and, when WOPI access is enabled, checks
the view. Without an override, WOPI uses Nextcloud's database connection.

Frontend builds
---------------

`make build` generates PHP-derived TypeScript types and entity metadata before
building the production frontend assets. It requires an installed Nextcloud
instance and its own database (SQLite is sufficient), but not the orchestra
MySQL/MariaDB database, a CafeVDB user, or `.clipassword`.

The app-local converter uses `11.7.0-MariaDB` as a representative DBAL platform
without opening the application connection. Encryption operations are disabled
and bound-event application subscribers are isolated in this metadata-only
process. Normal requests and the ORM CLI retain encryption, connection probing,
server-version detection and connection initialization.

`EntityRepositoryControllerTest` and the Vitest entity-response generators
likewise use an explicit MariaDB platform for serialization metadata. Their
repositories and entities are in-memory fixtures; they do not start an orchestra
database server and check that serialization leaves the connection unopened.

Office document credentials
---------------------------

A global response middleware provisions database-mount credentials for successful
richdocuments `DocumentController::token()`, `index()`, and
`createFromTemplate()` responses. It uses the returned token to load the
authoritative WOPI record, checks filesystem authorization and matching local
user identities, and only stores credentials for mounts marked `authenticated`.
Other responses and ordinary mounts are left unchanged.

CafeVDB stores the token hash, the token-encrypted app key, and the WOPI expiry
in the orchestra database's `WopiCredentials` table. The authenticated payload
also binds the key to the local user and expiry. The Nextcloud database account
reads the `SQL SECURITY DEFINER` connector view with `@WOPI_TOKEN_HASH` set for
that lookup only. The view returns at most one unexpired credential. Issuing a
new credential prunes expired rows. No Nextcloud authentication token is issued.

This covers only those three document-opening endpoints, not every richdocuments
token producer. The middleware provisions credentials; CafeVDB's mount
authentication service uses them after richdocuments validates the WOPI request.
`WopiRequestService` centralizes the richdocuments path compatibility check for
early service construction, when route metadata may be unavailable. Route
classification never replaces richdocuments' WOPI request validation.

The orchestra database migration installs the WOPI view and function on the
shared MariaDB server when cloud-user or personalized views are already
enabled. It grants the Nextcloud database account access to the view, not the
credential table. WOPI access to database storage has its own setting and
connector database in CafeVDB's settings; disabling cloud-user or personalized
views does not affect it. If the WOPI view is missing, enable or regenerate it
in the "WOPI access to DB-Storage" settings section.
Reopen Office documents that were open during the upgrade to get new credentials.

The Nextcloud app upgrade revokes all registered legacy password-based WOPI
credentials, including unexpired ones, before dropping `cafevdb_wopi_credentials`
and removing its cleanup job. Failures abort the migration so it can safely
retry. Unregistered legacy credentials remain subject to Nextcloud's token
cleanup. The richdocuments WOPI tokens themselves are not deleted; leave its
standard WOPI and template cleanup enabled.

Database-storage cache outbox
-----------------------------

Committed database-storage changes are queued in `DatabaseStorageCacheChanges`
until the Nextcloud file cache has been updated. A mounted storage is notified
after commit; otherwise, the queue is retried on later authorized access. There
is no background retry job. Reconciliation errors are logged with the storage
ID and relevant outbox row IDs; a batch-planning failure logs the entire pending
batch, not necessarily only the conflicting rows.

On storage access, reconciliation failures are logged. By default, unsafe cyclic
or interleaved rename batches block access; other failures allow access to
continue. The cache may be stale or partially updated until a retry succeeds.
Set the app configuration key `database_storage_cache_failure_policy` with
`occ config:app:set cafevdb database_storage_cache_failure_policy --value=VALUE`:
`fail` blocks access on every reconciliation failure, `continue` continues on
every failure, and `fail-on-conflict` (default) blocks on unsafe cyclic or
interleaved rename batches but continues on other failures. All modes retry on
later access. Invalid values block access until corrected. This setting does not change post-commit
notification or the outbox command.

Run `occ cafevdb:storage:cache-outbox` to list pending rows, including their
IDs, paths, creation times and creators. Use `--storage=ID` to select a storage,
`--summary` for counts and oldest creation times, or `--json` for a JSON listing.
The storage ID is the short ID shown by the listing, not a Nextcloud mount path.
The command requires credentials for an account that can decrypt the app database.

`--process` attempts reconciliation for the selected storage, or all pending
storages if no filter is given. It requires the storage to be mounted in the
command's context; remaining rows produce an error. If a batch cannot be
reconciled, inspect the logs and rows first. As a last resort, stop writes and
other reconciliation attempts for the affected storage, then use
`--clear --storage=ID --dry-run` to preview and `--clear --storage=ID` to delete
its pending rows. `--all` selects all storages instead; `--force` skips the
confirmation. Clearing discards pending updates and can leave the cache stale.

After clearing, rescan each affected mount with
`occ cafevdb:files-scan --path="/USER/files/MOUNT_PATH"` while it is accessible
to that user, before resuming normal access. A rescan may assign new file IDs to
renamed entries. Historical deletions outside the outbox also require a manual
file scan.

User database recryption
------------------------

Run `occ cafevdb:users:recrypt --user=USER` or
`occ cafevdb:users:recrypt --all` to force recryption, regardless of pending
recryption requests. The command prompts for the credentials of an account
that can decrypt the app database; `--user` selects the target, not that account.
`--all` selects all Nextcloud users, including those without a musician record.
For each linked musician this renews the row access token and recrypts their
bank accounts and encrypted file data, including soft-deleted entries. Users
with app permissions also receive the app encryption key.

Add `--dry-run` to enumerate without changing tokens, keys or encrypted entries.
Add `-v` or `--verbose` to list each encrypted entity's class and identifier as
it is processed (or would be processed in a dry run); decrypted contents are
not printed. Failures stop the command with a non-zero exit status.

Settings pages
---------------

The personal settings page contains user preferences, the user's encryption
key, and app information. Orchestra configuration is in the app's
administration settings, together with its existing delegated settings. The
right-hand navigation follows the visible sections; administration through
**Access Control** remains at the top. On narrow screens, use the navigation
toggle.

The moved orchestra sections still require membership in the configured
orchestra group, sub-admin rights for that group, and a valid encryption key.
Nextcloud delegation or instance-administrator status alone does not unlock
these sections. Existing delegated settings retain their previous permissions.
Personal preferences and orchestra configuration use separate controllers;
their existing HTTP endpoint URLs and stored configuration keys are unchanged.
If orchestra configuration cannot be loaded, those sections are hidden and an
error notice is shown. The existing administration controls remain available;
the server log contains the failure details.

Group metadata, including backend names, is loaded from the app's
`GET /apps/cafevdb/settings/cloud-groups` endpoint. Instance administrators can
search all groups; orchestra sub-admins can search only the configured
orchestra group and its authorization suffix groups. Group membership and
account details continue to use Nextcloud's provisioning API and its existing
per-group permission checks.

Testing problem reports
-----------------------

In the admin settings, open **Configure Problem Report Handling** and click
**Generate Test Error**. Use **report error** in the resulting dialog to preview
and submit the artificial error through the configured reporting methods.
Generating the error alone sends no report. Submission uses the real reporting
destinations, including the configured GitHub repository.
For GitHub token setup, including a private repository, see
[GitHub problem reports](doc/github-problem-reports.md).

App-local word splitter
-----------------------

`make build`, `make dev`, and `make appstore` build a Shiv zipapp containing the
Python splitter and its runtime dependencies, and download its German dictionary.
They do not require or copy the Python submodule's development virtualenv.
The app-store archive includes only `python/german-word-splitter.pyz` and
`python/german_utf8_linux.dic`, not the splitter's source tree, tests, or build tools.

Build prerequisites are Python 3.13 with `venv`/`pip`, `git`, `curl`, `7z`, and
`iconv`. Initialize `python/compound-word-splitting` first. The splitter can also
be built without Nextcloud:

```sh
make -f dev-scripts/word-splitter.mk word-splitter
```

`SPLITTER_PYTHON` selects the build interpreter (default: `python3.13`). The
destination server must have that same Python major/minor on the web server's
`PATH`, compatible native libraries, and the same OS/CPU architecture as the
build machine: dependencies include native extensions, so this is not a
universal binary. Python itself is not bundled. Build release archives on the
oldest supported Linux runtime, and build separately for other architectures.
No pip install or network access is needed on the destination server.

The app passes the installed dictionary path explicitly and extracts the zipapp
into a writable cache under its Nextcloud app-data directory; that filesystem
must permit loading native libraries. Required locales and `iconv` must be
installed on the server. A usable system-wide splitter is still preferred over
the bundled service.

To check the packaged executable, dictionary, and both splitting backends after
relocation, run `python3.13 tests/scripts/word-splitter-smoke.py <appstore.tar.gz>`.
CI runs this against the unsigned app-store archive.

Outgoing email form
-------------------

The outgoing email form uses a Vue envelope filling the legacy content area,
mounted through the component event bus. Vue owns the tabs and common form
structure; the initial form endpoint supplies the existing PHP-rendered
recipients and composition fragments, hidden form data and upload/manual
metadata instead of a complete
outer template. All panels remain mounted inside one form when switching tabs.
Minimize leaves the form and its auto-save timer alive; restore returns to the
same message. The editor, attachments and status/preview rendering remain
legacy-owned. The Vue component never imports the legacy JS asset: typed
`EMAIL_FORM_COMMAND` and `EMAIL_FORM_EVENT` bus messages coordinate tab changes,
activation hooks, title, blocking, close and lifecycle cleanup.
Closing from the preview returns to composition; closing from composition uses
the existing cancel and draft-cleanup flow. Sending, loading a message and
uploading attachments block the envelope controls until the operation completes.
Removing the containing legacy view also tears down the envelope and its
legacy widgets, after any protected operation finishes.
The panels fill the available envelope width without the old dialog width caps.
The envelope and restore button are absolutely positioned within the existing
legacy content container. Its flex layout determines their available space,
including sidebar changes, without navigation observers or manual offsets.

Email template substitutions
----------------------------

Email templates support substitutions in both the subject and the body,
for example `${GLOBAL::PROJECT}` and `${MEMBER::FIRST_NAME}`. Per-member
substitutions in either field generate a separate email for each recipient
and cannot be used for mailing-list posts. The Cc/Bcc and catch-all copy
resolves global substitutions but keeps the per-member placeholders.
Use `$$` to escape a literal dollar sign. Stored templates and drafts retain
their placeholders.

German bank validation data is stored through Doctrine/ORM in the Nextcloud
database, not in the orchestra database or a vendor bank-list file. It uses
the same unprefixed `bav_bank`, `bav_agency`, and `bav_meta` tables as the
optional BAV Nextcloud app, so existing bank data is shared even though each
app has its own namespace-wrapped library. The BAV app is not required.
Missing bank tables are installed and populated automatically; downloads
for installation and explicit updates use temporary files.

Internal calendar updates
-------------------------

Internal calendar updates use `Service\DTO\CalendarEventChangeSet` or
`CalendarTaskChangeSet`, derived from `CalendarEntryChangeSet`. Pass these
objects directly to `EventsService::updateCalendarEvent()`,
`updateCalendarTask()`, or `updateCalendarEntry()`; `toArray()` serializes dates
and is not suitable for this service boundary. Null properties mean "leave
unchanged"; empty strings clear text, `alarm: 0` or `alarm: []` clears alarms,
and `related: []` clears relations. Event dates are immutable snapshots;
all-day end dates are inclusive in the DTO and exclusive in iCalendar.
`allDay` controls supplied dates, and an omitted end never defaults to the start.
Calling `updateCalendarEntry()` without a change-set still writes the existing
calendar object unchanged.

PHP code quality
----------------

The PHPCS and PHPMD workflows check PHP files in `lib/` using `.phpcs.xml`
and `.phpmd.xml`. They omit `templates/`, `lib/Service/IMAP/`, legacy PhpMyEdit
in `lib/Legacy/PhpMyEdit/`, and generated Doctrine ORM proxies in
`lib/Database/Doctrine/ORM/Proxies/`.
PHPCS reports warnings but only fails CI on errors.

To run the same checks locally, initialize the Makefile submodules and ensure
the `xpath` binary is installed (`libxml-xpath-perl` on Debian/Ubuntu). The
targets install their isolated Composer tools when needed:

```sh
git -c url."https://github.com/".insteadOf="git@github.com:" submodule update --init dev-scripts/lib dev-scripts/MakeHelp
make phpcs
make phpmd
```

PHPMD runs with one thread because its patched entry point cannot dispatch
worker subcommands.

The app's Doctrine dependencies are namespace-wrapped with PHP-Scoper. Its
configuration rewrites dynamic class-constant lookups in Doctrine's attribute
driver and DQL lexer, and CI verifies attribute metadata, SQLite schema
creation, and DQL execution against the wrapped ORM.

--
cjh
