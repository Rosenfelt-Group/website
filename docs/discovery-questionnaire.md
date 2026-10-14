# Discovery questionnaire

A tokenized pre-call questionnaire at `https://rosably.com/discovery/?t=<token>`.
Invites are created in the dashboard (CRM → Discovery). Answers and the generated
pre-call brief land in Supabase `crm.discovery_*`, which the dashboard reads.

## Files

| File | Purpose |
|---|---|
| `mu-plugins/rosably-discovery.php` | Serves `/discovery/`, REST endpoints, brief generation |
| `mu-plugins/rosably-discovery/discovery.html` | The form (React via CDN, no build step) |
| `mu-plugins/rosably-discovery/questionnaire-v1.json` | Questions. Edit here; bump to `v2` for breaking changes |

## Endpoints (`/wp-json/rosably/v1/discovery/…`)

| Route | What it does |
|---|---|
| `GET invite?token=` | Loads the invite, prefill data, saved answers, and the sections for its project type. Marks it opened. |
| `POST save` | Autosave (`{token, answers, current_section}`) |
| `POST submit` | Validates required answers, stores them, logs a lead activity, queues a brief, emails Brian and the respondent |
| `POST brief` | Generates the queued brief. Only acts on a `pending` row it can claim, so repeated calls do not add Anthropic spend. The form calls it after submit; the dashboard's Regenerate button calls it too. |

The token is the only credential. Answers are cleaned against the questionnaire
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

1. Dashboard → CRM → Discovery → New discovery (use your own email) → copy the link.
2. Open it, answer a few questions, close the tab, reopen: answers should still be there.
3. Submit. You should get both emails, and within about a minute the brief appears on the dashboard detail page.
