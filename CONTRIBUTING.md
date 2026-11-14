# Contributing

Thank you for helping. Changes come back as **pull requests** to this repository.

## How to send a change
1. Fork the repository and make your change on a branch.
2. Keep it to one thing and keep it small enough to review. Run `node --check` on JavaScript you touched and
   `php -l` on PHP.
3. Database changes are a new numbered file in `db/migrations/` that also records itself in `app.schema_migrations`
   (see AGENTS.md). Never edit an old migration.
4. Do not include real customer data, credentials, addresses of real people, or anything you do not have the right
   to publish. Test data must be made up.
5. Sign off every commit (`git commit -s`). That adds a `Signed-off-by:` line and means you agree to the
   Developer Certificate of Origin 1.1 (https://developercertificate.org) and to the licence grant below.
6. Open the pull request and say what problem it solves.

## Licence of contributions
You keep the copyright in what you write. By contributing you agree that:
- your contribution is licensed to everyone under the GNU AGPL v3 or later, like the rest of the software, and
- you also grant **Tinuum Networks Inc.** a perpetual, worldwide, non-exclusive, royalty-free, irrevocable licence
  to use, copy, modify, sublicense and distribute your contribution, including under other licences. This is what
  lets Tinuum keep publishing this software while it also uses it in its own business.

Tinuum Networks Inc. keeps the copyright in the project as a whole. If you cannot agree to this, please open an
issue instead of a pull request.

## Running a modified version
You do not have to send changes back to Tinuum. If you let other people use a modified version over a network,
the AGPL v3 (section 13) requires you to offer them the source of that version. Sending your changes here
instead is the simplest way to meet that duty and keeps your fork small.

(This text is a plain-language agreement, not legal advice. Have a lawyer review it before you rely on it.)
