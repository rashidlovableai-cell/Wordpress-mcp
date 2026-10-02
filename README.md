# Rashid Agency MCP v1.0.0
**Created by Rashid Ahmad.**

Turns Claude into a small WordPress agency team: strategist, designer, developer, copywriter, SEO, QA. One plugin, 12 compact tools, built to be fast and light on tokens.

> Use it on a development or staging site first. It was syntax-checked, not yet tested on a live WordPress install.

## Install in 3 steps
1. **Plugins > Add New > Upload Plugin**, upload `rashid-agency-mcp.zip`, Activate.
   (Pantheon: put the Dev environment in SFTP mode first.)
2. Open **Agency MCP** in the admin menu and click **Generate new token**. It is shown once.
3. **Copy and paste into Claude:**
   - **Option A (easiest):** Claude > Settings > Connectors > Add custom connector > paste the one URL.
   - **Option B:** copy the config into `claude_desktop_config.json` (needs Node.js), then File > Exit and reopen Claude.
   - **Option C:** copy the `claude mcp add ...` command for Claude Code.

Test: "Give me a brief of my site."

## How the team works
Say what you want in any language ("make a modern green homepage for my English academy in Urdu and English"). Claude then:
1. calls `brief` (site + your profile),
2. loads the roles it needs with `skill` (only when needed, so tokens stay low),
3. builds with `batch` (many actions in one request),
4. checks the result with `qa`,
5. reports in a few lines, with undo ids and backups.

Built-in skills: strategist, designer, developer, copywriter, seo, ecommerce, translator, qa, security, performance. Add your own in **Agency MCP > Custom skills** (`## name`, then instructions).

## Your profile (Agency MCP page)
Business description, brand voice, languages, brand colors, rules. Claude reads it every job, so results match you without repeating yourself.

## The 12 tools
| Tool | What it does |
|---|---|
| brief | Site snapshot, profile, switches, skills list (call first) |
| skill | Load role playbooks on demand |
| batch | Up to 15 tool calls in one request |
| content | List/get/save/delete posts, pages, any post type (bulk save) |
| media | List, upload from URL (bulk), alt text |
| design | Logo, icon, title, tagline, Additional CSS, theme mods/options, switch theme, child theme, **undo** |
| plugins | List, activate, deactivate, install plugins/themes |
| code | List/read/write theme and plugin files, backups, restore |
| seo | Yoast / Rank Math / built-in: get, set (bulk), audit |
| rest | Any WordPress REST route (menus, widgets, WooCommerce, other plugins) |
| qa | Visits pages and checks speed, mobile, H1, alt, PHP errors, mixed content |
| site | Flush caches (incl. Elementor CSS), read debug.log |

## Safety
- **Read-only mode** switch. File writes and PHP writes are **off** by default (your switches).
- Every design change is **undoable**; every overwritten file is **backed up**; PHP is syntax-checked first.
- Destructive or high-impact actions need `confirm=true`, so Claude asks you first.
- Blocked options (siteurl, home, default_role, active_plugins...). Claude cannot edit this plugin or run arbitrary PHP/SQL/shell.
- Token stored as a hash, revocable, rate-limited (240 requests/min), full activity log.
- Option A puts the token in the URL: treat that URL like a password. Option B/C send it in a header (safer).

## Troubleshooting
- Server not showing in Claude Desktop: fix JSON, save, File > Exit, reopen.
- "Invalid token": generate a new one. If the host strips the Authorization header, use Option A.
- Install or file write fails: filesystem may be read-only (Pantheon Dev needs SFTP mode).
- Windows: if PowerShell blocks `npx`, use `npx.cmd`.

## Limits
Elementor/Divi layouts are stored in post meta and can be read/written via `content` (meta_keys), but results vary. Theme options differ per theme: read first (`design opt`). Take a backup before big changes.

License: GPL-2.0-or-later. Author: Rashid Ahmad.
