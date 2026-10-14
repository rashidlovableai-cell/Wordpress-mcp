=== Rashid Ahm MCP ===
Version: 2.1.0
Made from scratch by Rashid Ahmad.  Help: rashid.ahm199@outlook.com
Requires WordPress 5.8+ and PHP 7.4+

An AI agency team for WordPress. Connect Claude, ChatGPT or any app that supports remote MCP.
Give each AI account a role (Supervisor, Designer, SEO, Copywriter, Marketing, Translator,
QA, Developer, Store manager, or Solo for one account). Watch every change in the Live Studio,
edit directly in the preview, say yes in the chat and the change goes live, undo for 30 days.

== Install ==
1. WordPress admin > Plugins > Add New > Upload Plugin > choose the zip > Install > Activate.
2. Open "Rashid Ahm MCP" in the left menu > Connect AI > tick roles > Generate keys.
3. Copy each MCP link into an AI app (Settings > Connectors / Remote MCP server).
4. Paste the "starting command" shown under the link into that AI's chat and write the task.

== What is inside ==
* Live Studio - one slim toolbar and a big preview: page picker, laptop/tablet/mobile views, Edit mode,
  Reload, and a Live light. The preview refreshes by itself when the site changes. In Edit mode click
  text or an image, then use the small bar: edit text, replace image, add image, delete.
* Connect AI - one key per AI account, expiry 24 hours / 3 days / 30 days / 1 year, shown once.
* Expert scores - SEO, copywriter, designer, speed, marketing and quality scores out of 100,
  measured on your real pages, plus the experts' written reports.
* Activity - last 500 actions with CSV/JSON export and an Undo link on every change.
* Settings - English/Urdu, read-only mode, file and PHP edit switches, maintenance page, brand profile.
* 16 MCP tools: brief, skill, batch, content, media, design, elementor, plugins, code, seo, rest,
  qa, site, inbox (notes and reports), audit, history.

== How changes go live ==
* Drafts, notes and reports need no confirmation.
* Anything visitors can see needs your yes. The AI asks you in the chat; when you say yes it repeats the
  call with confirm=true and the change goes live on the site right away. There is no approval queue.

== Safety ==
* Every change takes a backup first and can be undone for 30 days (pages, Elementor layouts, CSS,
  settings, theme and plugin files). PHP is syntax-checked before it is written.
* Keys are stored hashed, expire, and can be turned off at any time. Treat each MCP link like a password.
* Read-only mode lets AI look but not change anything.
* This plugin cannot edit its own files or install itself.

== Known limits ==
* Direct editing in the Live Studio needs the site address and the admin address to be on the same
  domain. Text that comes from the theme (header, footer, widgets) is edited from the Code tab or by the AI.
* Elementor: click-to-edit works on Elementor's own text and image widgets. Other widgets are edited by the AI.
* If the preview is blank the Studio now says why (for example a 403 from your site or firewall).
* On managed hosts such as Pantheon Dev, switch to SFTP mode before installing plugins or editing files.
* AI accounts cannot message each other. The team inbox in WordPress is the shared meeting place.

== Changelog ==
2.1.0 - Approvals removed: your yes in the chat makes the change live. Studio side panel removed (toolbar only).
      Preview problems (403, blocked frame) are explained. AI apps that prefix tool names (mcp__Rashid__rest) work.
2.0.0 - New plugin: Live Studio, role keys, approvals, scores, undo history, Urdu dashboard.
