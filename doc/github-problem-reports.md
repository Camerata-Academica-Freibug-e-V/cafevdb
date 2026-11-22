# GitHub problem reports

The GitHub reporting channel can create issues in the private
`Camerata-Academica-Freibug-e-V/cafevdb-problem-reports` repository and add
comments to an existing issue when the same problem is reported again. It runs
as the GitHub account that owns the configured token, not as the reporting
user.

## Create a token

Use a fine-grained personal access token belonging to an organization member
who already has access to the private repository and can create issues and
comments there. A token cannot grant its owner access they do not have.
Repository issues must be enabled.

1. On GitHub, open your profile menu, then **Settings** > **Developer
   settings** > **Personal access tokens** > **Fine-grained tokens** >
   **Generate new token**.
2. Enter a name and choose an expiration. Under **Resource owner**, select
   `Camerata-Academica-Freibug-e-V`.
3. Under **Repository access**, choose **Only select repositories** and
   select `cafevdb-problem-reports`.
4. Under **Repository permissions**, set **Issues** to **Read and write**.
   GitHub also grants **Metadata** read access automatically. No other
   permissions are needed for this channel.
5. Generate the token and copy it securely. If the organization requires
   approval, an organization owner must approve the token before it can access
   the private repository. If the organization or repository cannot be selected,
   check the account's access and the organization's token policies.

See GitHub's [personal access token documentation](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens)
for current instructions and organization policy details.

## Configure and verify

1. In the app's administration settings, open **Configure Problem Report
   Handling**. The GitHub fields are visible to the configured orchestra
   sub-admin.
2. Enter `Camerata-Academica-Freibug-e-V/cafevdb-problem-reports` in
   **GitHub Repository for Problem Reports (owner/name)** and submit the
   field.
3. Paste the token into **GitHub Access Token** and submit that field. The
   app stores it encrypted with the Nextcloud server secret. Treat the token
   like a password; do not put it in the repository or share it in reports.
   Replace it before it expires or if the owning account loses access.
4. Click **Generate Test Error**, then use **report error** in the dialog and
   submit the report. Check the submission result and the private repository
   for the issue or, for a repeated fingerprint, a new comment. This creates a
   real report; generating the error without submitting it does not.

Saving the token checks access to the repository, not permission to create
issues or comments. If submission fails, check token approval and expiration,
repository access, enabled issues, and **Issues: Read and write**. The
configured email channel, if any, is attempted separately.

The report preview currently calls the GitHub destination "public" even when
the configured repository is private. GitHub restricts a private issue to
users with access to that repository; reporters without access cannot open its
link. Reports are sanitized and use a pseudonym, but review the preview for
information that should not be shared before submitting.
