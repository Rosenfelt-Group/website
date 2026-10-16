# Discovery questionnaire

A tokenized pre-call questionnaire at `https://rosably.com/discovery/?t=<token>`.
Invites are created in the dashboard (CRM → Discovery). Answers and the generated
pre-call brief land in Supabase `crm.discovery_*`, which the dashboard reads.

## Where the questions come from

Questions live in the dashboard: a **question bank** (`crm.discovery_questions`)
and **templates** (`crm.discovery_templates` + `discovery_template_items`), managed
under CRM → Discovery → Templates / Question bank. "Standard Discovery" is the
original v1 questionnaire.

New discovery creates a **draft** invite from a template. Its questions are copied
into `discovery_invites.questionnaire_snapshot`, edited for that prospect, and
frozen when you press **Send** (a database trigger rejects later changes). This
plugin renders that snapshot, so editing a template or bank question never changes
a questionnaire that's already out. Invites without a snapshot fall back to
`questionnaire-<version>.json`.

The snapshot has the same shape as `questionnaire-v1.json` (`sections[].fields[]`),
plus optional `intro` (welcome text), `template {id, name}` and `estimated_minutes`.
Field types: `text`, `textarea`, `single`, `multi`, `scale`, `yes_no`, and the
legacy `email` and `tool_rows`. Extra answer keys: `<id>__other` (the "Other" text
for choice questions) and `<id>__detail` (a `yes_no` follow-up, shown when the
answer equals the field's `followUpOn`, labelled `followUpLabel`). `showIf` and
section `modules` (project-type filters, v1 only) work as before.

## Files

| File | Purpose |
|---|---|
| `mu-plugins/rosably-discovery.php` | Serves `/discovery/`, REST endpoints, brief generation |
| `mu-plugins/rosably-discovery/discovery.html` | The form (React via CDN, no build step) |
| `mu-plugins/rosably-discovery/questionnaire-v1.json` | The v1 questionnaire, used only for invites without a snapshot. The dashboard's migration seeded the bank from it; edit questions in the dashboard now. |

## Endpoints (`/wp-json/rosably/v1/discovery/…`)

| Route | What it does |
|---|---|
| `GET invite?token=` | Loads the invite, prefill data, saved answers, and the sections for its project type. Marks it opened. With `&preview=1` it also loads drafts, returns no saved answers and doesn't mark it opened. |
| `POST save` | Autosave (`{token, answers, current_section}`) |
| `POST submit` | Validates required answers, stores them, logs a lead activity, queues a brief, emails Brian and the respondent |
| `POST brief` | Generates the queued brief. Only acts on a `pending` row it can claim, so repeated calls do not add Anthropic spend. The form calls it after submit; the dashboard's Regenerate button calls it too. |

The token is the only credential. Draft tokens return "not valid" unless
`preview=1` is set, and `save`/`submit` always refuse drafts, so a preview can't
write anything. The form in preview mode shows a banner, skips autosave and the
required-field checks, and disables Submit. Answers are cleaned against the questionnaire
definition (unknown keys dropped, lengths capped). The page sends
`Referrer-Policy: no-referrer` so the token never leaks to third parties.

## Secrets (`rosably-secrets.php`)

Reuses the existing `ROSABLY_SUPABASE_URL`, `ROSABLY_SUPABASE_SERVICE_KEY`
and `ROSABLY_ANTHROPIC_API_KEY`. Optional:

```php
define( 'ROSABLY_DASHBOARD_URL', 'https://<your-dashboard-domain>' ); // adds a link to the alert email
define( 'ROSABLY_DISCOVERY_MODEL', 'claude-sonnet-4-6' );           // brief model override
```

## Deploy

Same pattern as the other mu-plugins: no image rebuild needed.

```bash
cd /opt/website && git pull
docker cp mu-plugins/rosably-discovery.php wordpress-wordpress-1:/var/www/html/wp-content/mu-plugins/
docker cp mu-plugins/rosably-discovery     wordpress-wordpress-1:/var/www/html/wp-content/mu-plugins/
```

The Dockerfile already copies all of `mu-plugins/`, so a rebuild picks these up too.

## Smoke test

1. Dashboard → CRM → Discovery → New discovery (use your own email), pick a template → the draft editor opens.
2. Add, remove or reorder a question and edit the intro, then **Preview ↗**: the form shows the Preview banner and your changes. Open the plain link without `&preview=1`: it says the link is not valid.
3. **Send**, copy the link, open it, answer a few questions, close the tab, reopen: answers should still be there.
4. Edit the template it came from. The sent questionnaire and its Questions sent tab don't change.
5. Submit. You should get both emails, and within about a minute the brief appears on the dashboard detail page.
