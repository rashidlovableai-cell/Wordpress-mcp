# Rashid Ahm MCP - Setup Guide

Made from scratch by Rashid Ahmad. Help: rashid.ahm199@outlook.com

## 1. Install
1. WordPress admin > **Plugins > Add New > Upload Plugin**.
2. Choose `rashid-ahm-mcp.zip`, press **Install Now**, then **Activate**.
3. A new menu **Rashid Ahm MCP** appears on the left.

On Pantheon (or similar managed hosting) switch the Dev environment to **SFTP mode** first. Without it, WordPress cannot install plugins or write files.

## 2. Generate keys (one key per AI account)
1. Open **Rashid Ahm MCP > Connect AI**.
2. Tick the roles you need. A good team: **Supervisor + Designer + SEO + Copywriter**. If you only have one AI account, tick **Solo**.
3. Choose how long the keys last: 24 hours, 3 days, 30 days or 1 year.
4. Press **Generate keys**. Each key shows an **MCP link** and a **starting command**.
5. **Copy them now.** For safety the link is shown only once. Treat it like a password.

## 3. Connect each AI
- In the AI app open its connector settings (for example Claude: Settings > Connectors > Add custom connector) and paste **one MCP link**. Ask for "remote MCP server" or "custom connector" wherever the app offers it.
- Use a different AI account for each role (for example ChatGPT account 1 = Supervisor, account 2 = SEO, Claude = Designer).
- Whether a free or paid plan allows custom connectors depends on the AI app, so check its settings.

## 4. Give each AI its first message
Paste the **starting command** from the dashboard into that AI's chat and write your task where it says `[write your task here]`. Example for the SEO account:

> My task: Review the Home, About and Contact pages and improve the SEO titles and descriptions.

You can start the roles one after another. They do not have to work at the same time.

## 5. How a change goes live
1. The AI tells you its plan in the chat and asks: "Do you want this or not?"
2. When you say **yes**, it makes the change and the change goes **live on your website right away**.
3. Drafts need no yes. Only things visitors can see do.
4. Every change is backed up first. If you do not like it, press **Undo** next to it in **Activity** (kept for 30 days).

There is no approval queue and no approval screen: your yes in the chat is the approval.

## 6. Live Studio
Open **Rashid Ahm MCP > Live Studio**. It is one toolbar and one big preview.
- Pick a page. Switch between **Laptop, Tablet and Mobile**.
- The preview **refreshes by itself** when something changes. A line under the toolbar shows the last change.
- If the preview cannot be shown, a message tells you why (for example "your site answered with error 403") with a link to open the page in a new tab.
- Turn on **Edit mode**, then click any text or image. A small bar appears: **Edit text** (or double-click the text and type), **Replace image**, **Add image after**, **Delete**. The **Add image** button opens the media library, where you can also drag and drop a new picture.

## 7. Settings
- **Language:** English or Urdu (dashboard).
- **Read-only mode:** AI can look but not change.
- **File and PHP edit switches**, **Maintenance page**, and a **Brand profile** (name, tone, colors, rules) that every AI reads.

## 8. Expert scores
**Expert scores** measures SEO, copywriting, design, speed, marketing and quality out of 100 on your real pages. The AI experts can also post written reports there.

## 9. If something goes wrong
| Problem | What to do |
|---|---|
| "This key has expired" | Generate a new key in Connect AI. |
| Link shows 404 | Settings > Permalinks > Save once, or use the link form with `?rest_route=`. |
| Security plugin blocks it | Allow the REST route `/wp-json/rahm/v1/` in your firewall or security plugin. |
| Direct editing is blocked | The site address and admin address must use the same domain. |
| A change looks wrong | Activity > **Undo** next to that change. |
| Preview is blank or shows an error | Read the message under the toolbar. A 403 means your site, firewall or hosting is blocking the page. Open it in a new tab to see the real message. |
| Activity shows "Unknown tool" | Update to this version. It accepts tool names with prefixes like `mcp__Rashid__rest`. |
| Anything else | rashid.ahm199@outlook.com |
