# Computer Cat API — Game Integration Guide

This guide explains how to integrate a new game with the Computer Cat platform. Give this document to your AI assistant when building the integration.

## Overview

Computer Cat is a multi-game backend that provides:
- **Anonymous authentication** — players get a token automatically, no login required
- **Leaderboards** — daily/weekly/monthly rankings with anti-cheat validation
- **Achievements** — define badges in the admin panel, evaluate client-side, report to server
- **Cloud saves** — store arbitrary JSON blobs with optimistic locking (version-based)
- **Purchases** — receipt verification for App Store / Google Play (coming soon)

**Base URL:** `https://api.computercat.co/api/v1`
**Admin panel:** `https://api.computercat.co/admin`

---

## Step 1: Create your game in the admin panel

1. Log in at `https://api.computercat.co/admin`
2. Go to **Games** > **Create**
3. Fill in:
   - **Name**: Your game's display name
   - **Slug**: URL-friendly identifier (e.g. `my-puzzle-game`). This is used in all API calls.
   - **Settings** (JSON): Game-specific config. The API stores it but doesn't validate it — your client owns the schema.
4. Set **Active** to true

### Configure leaderboards

Go to your game > **Leaderboard Types** tab > **Create**:
- **Slug**: e.g. `daily-time`, `high-score`
- **Sort direction**: `asc` for time-based (lower is better), `desc` for score-based (higher is better)
- **Score label**: Display text like "Time" or "Score"
- **Period**: `daily`, `weekly`, `monthly`, or `all_time`
- **Max entries per period**: Default 100

### Configure achievements

Go to your game > **Achievement Definitions** tab > **Create**:
- **Slug**: unique identifier (e.g. `first_win`, `streak_7`)
- **Name**: Display name
- **Description**: How to earn it
- **Icon**: Emoji or icon identifier
- **Is secret**: Hidden until unlocked

---

## Step 2: Integrate the API in your client

### Authentication

On first app launch, create an anonymous user:

```
POST /v1/auth/anonymous
Content-Type: application/json
```

Response:
```json
{
  "token": "1|abc123...",
  "user": {
    "id": 42,
    "display_name": "Player_42",
    "email": null,
    "is_anonymous": true
  }
}
```

Store the token in localStorage. Include it in all subsequent requests:

```
Authorization: Bearer 1|abc123...
```

To verify a stored token is still valid:
```
GET /v1/auth/me
Authorization: Bearer <token>
```

### Leaderboards

**Get current period rankings:**
```
GET /v1/games/{slug}/leaderboards/{type}
```

**Get specific period:**
```
GET /v1/games/{slug}/leaderboards/{type}/{periodKey}
```
Period key format: `2026-03-05` (daily), `2026-W10` (weekly), `2026-03` (monthly), `all` (all-time).

**Submit a score:**
```
POST /v1/games/{slug}/leaderboards/{type}
{
  "score": 45200,
  "metadata": {
    "hintsUsed": 0,
    "level": "hard"
  }
}
```
The server keeps only the best score per user per period. `metadata` is optional JSON stored alongside the score.

**Get my rank:**
```
GET /v1/games/{slug}/leaderboards/{type}/me
```

### Achievements

**List all achievement definitions:**
```
GET /v1/games/{slug}/achievements
```

**Get my unlocked achievements:**
```
GET /v1/games/{slug}/achievements/me
```

**Report newly unlocked achievements:**
```
POST /v1/games/{slug}/achievements
{
  "slugs": ["first_win", "streak_7"]
}
```

Achievement evaluation happens client-side. The server just records which ones are unlocked and when. This keeps the game responsive and works offline.

### Cloud Saves

Cloud saves store arbitrary JSON. The client owns the schema — the server just stores and returns it.

**Get a save:**
```
GET /v1/games/{slug}/saves/{key}
```
Default key is `main`. You can use multiple keys for different save slots.

**Save/update:**
```
PUT /v1/games/{slug}/saves/{key}
{
  "data": { ... your game state ... },
  "version": 0,
  "checksum": "optional-sha256"
}
```

**Version (optimistic locking):**
- First save: send `version: 0`
- Subsequent saves: send the `version` from the last response
- If versions don't match (409 Conflict), fetch the latest, merge, and retry

**Delete a save:**
```
DELETE /v1/games/{slug}/saves/{key}
```

---

## Step 3: Recommended architecture

### Offline-first pattern

1. Use localStorage as primary storage (fast, works offline)
2. On app boot: authenticate, pull cloud save, merge with local, push merged state
3. After state changes: debounce and push to cloud (e.g. 3 seconds after last change)
4. On version conflict (409): pull latest, merge, retry push

### Merge strategy

When merging local and cloud state, use these rules:
- **Scores/results**: Keep the best per puzzle/level (e.g. lowest time, highest score)
- **Achievements**: Union of both sets (never lose an unlock)
- **Purchases/ownership**: Union (never lose a purchase)
- **Settings**: Cloud wins (most recently saved)
- **Streaks**: Keep the highest value

### Example merge function (TypeScript)

```typescript
function mergeState(local: GameState, cloud: GameState): GameState {
  return {
    results: mergeResults(local.results, cloud.results), // keep best per key
    achievements: [...new Set([...local.achievements, ...cloud.achievements])],
    settings: cloud.settings, // cloud wins
    streak: Math.max(local.streak, cloud.streak),
  };
}
```

---

## Step 4: CORS

Your game's domain needs to be allowed. Contact the admin to add your domain to the CORS config, or add it yourself in `config/cors.php`:

```php
'allowed_origins' => [
    'https://yourgame.app',
    'https://*.yourgame.app',
    'http://localhost:*', // dev
],
```

---

## Error responses

All errors return JSON:

```json
{
  "message": "Description of what went wrong",
  "errors": {
    "field_name": ["Validation message"]
  }
}
```

Common status codes:
- `401` — Missing or invalid auth token
- `403` — Banned user
- `404` — Resource not found
- `409` — Version conflict (cloud saves)
- `422` — Validation error
- `429` — Rate limited

---

## Rate limits

- Authentication: 10 requests/minute
- Score submission: 30 requests/minute
- All other endpoints: 60 requests/minute

---

## Full API reference

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/v1/auth/anonymous` | No | Create anonymous user |
| POST | `/v1/auth/login` | No | Login with email/password |
| POST | `/v1/auth/register` | Yes | Upgrade anonymous to email account |
| GET | `/v1/auth/me` | Yes | Get current user profile |
| PATCH | `/v1/auth/me` | Yes | Update display name |
| DELETE | `/v1/auth/me` | Yes | Delete account (GDPR) |
| GET | `/v1/games` | No | List active games |
| GET | `/v1/games/{slug}` | No | Game details + leaderboard types |
| GET | `/v1/games/{slug}/leaderboards/{type}` | Yes | Current period rankings |
| GET | `/v1/games/{slug}/leaderboards/{type}/{period}` | Yes | Specific period rankings |
| POST | `/v1/games/{slug}/leaderboards/{type}` | Yes | Submit score |
| GET | `/v1/games/{slug}/leaderboards/{type}/me` | Yes | My rank |
| GET | `/v1/games/{slug}/achievements` | Yes | List achievement definitions |
| GET | `/v1/games/{slug}/achievements/me` | Yes | My unlocked achievements |
| POST | `/v1/games/{slug}/achievements` | Yes | Report unlocked achievements |
| GET | `/v1/games/{slug}/saves` | Yes | List save slots |
| GET | `/v1/games/{slug}/saves/{key}` | Yes | Get save data |
| PUT | `/v1/games/{slug}/saves/{key}` | Yes | Save/update with version lock |
| DELETE | `/v1/games/{slug}/saves/{key}` | Yes | Delete save |
| GET | `/v1/purchases` | Yes | List purchases |
| POST | `/v1/purchases/verify` | Yes | Verify receipt |

## Intresseanmälan (interest signup)

`POST /api/v1/games/{slug}/interest` (publik, ingen token) med `email` och `language` (`de`, `es`, `fr` eller `other`). Svarar alltid `202`, oavsett om adressen redan fanns; `confirmation_sent` säger om ett bekräftelsemail gick iväg, och `message` är formulerat efter det. Begränsat till 3 anrop per timme och adress (och 60 per minut och IP).

- Dubbel bekräftelse: ett mail med länkarna `/api/v1/interest/{token}/confirm` och `/unsubscribe` skickas via standardmailaren (Mailgun EU, se `config/services.php`). Står `MAIL_MAILER` på `log` sparas anmälan ändå, men utan mail, och en varning loggas.
- Obekräftade anmälningar raderas 30 dagar efter att bekräftelsemailet skickades, av `interest:prune-unconfirmed` (schemaläggaren kör). Anmälningar som aldrig fått mail raderas inte.
- Avregistreringslänken visar en knapp (POST); en GET raderar ingenting, eftersom mailklienters länkskannrar öppnar länkar automatiskt.
- Listan finns i admin under Game Data → Interest signups.
- `site_url` i spelets settings används för länken "Tillbaka till …" efter bekräftelsen.

`GET /api/v1/games` och `/games/{slug}` returnerar inte längre `settings`: fältet bär hemligheter per spel.
