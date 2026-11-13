# Written messenger deployment

This replaces `/admin/messenger` with the staging design connected to authenticated Laravel APIs and the existing conversation/message records. No sample records or browser message database are shipped.

Deploy the branch normally, then run against the panel installation:

```sh
php artisan migrate --force
php artisan optimize:clear
```

No frontend build is required. New assets are versioned public JS/CSS. The migration adds saved-space ownership, per-user stars and drafts, edit history, and presence sessions. It does not rewrite existing conversations/messages or change their enums. Existing database backups and the normal deployment procedure still apply.

Personal space is a single ordinary private conversation mapped to its owner. It is always first and pinned, outside tab/search filters. Other users cannot access or forward its contents. New attachments use the private `local` filesystem and an authenticated download endpoint; the PHP process must be able to write its normal local storage directory. Images/documents are limited to 5 MB. Calls and voice/video submissions are disabled on both the new and legacy endpoints; historical media records remain in the database.

The new UI provides joined conversations, staff contacts, group/channel creation, replies, emoji reactions, personal stars, forwarding, personal notes, server drafts, author-only edits with history, pagination and history search. Channel posting permissions and left-membership restrictions are enforced server-side. Existing task/announcement APIs remain available; their old inline controls are not part of the replacement view. The old global call widget is replaced by a link to the messenger, so it cannot request microphone access on other admin pages.

Presence requires a visible messenger tab heartbeat within 35 seconds and respects manual offline status. A closed/disconnected tab expires automatically. It is a recent connection signal, not a guarantee of human attention. Messages refresh every 15 seconds while the messenger is visible.

Validation: `php tests/text-messenger.php` uses a separate in-memory SQLite connection, creates only test schemas and checks authorization, saved-space uniqueness, forwarding/reply boundaries, stars/drafts, edit audit, disabled audio/calls, presence freshness, channel permissions, history pagination/search, and rollback safety. `node tests/text-messenger-client.cjs` covers navigation races and client rules. Run PHP lint and `node --check public/js/text-messenger.js` as syntax checks.

After deploying, smoke-test the real panel with two staff accounts: send and read a written message, forward to personal space, star/unstar, react, edit, reload a draft, upload/download an image or document, and confirm channel posting permissions. Verify mobile navigation and desktop layout in the actual panel shell. This change has not run production migrations or deployed the live panel.
