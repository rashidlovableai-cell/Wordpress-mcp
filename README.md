# Class Hub

Password-protected download page for a class. Students see a welcome page, enter the class password, then see only Download buttons. You manage files at `/admin`.

## Structure
```
class-hub/
  public/index.html     student page (password + downloads)
  public/admin.html     your upload, delete and QR page
  public/style.css      shared styling
  lib/server.js         Express API (auth, Supabase, QR)
  api/index.js          Vercel entry point
  vercel.json           Vercel routing
  supabase-setup.sql    table + private bucket
  (no secrets here: copy .env.example to .env locally)
```

## Setup
1. Supabase > SQL Editor: paste `supabase-setup.sql` and Run. Check Table Editor shows `resources` and Storage shows `class-files`.
2. Local test: `npm install` then `npm start`. Open http://localhost:3000 (students) and /admin (you).

## Deploy on Vercel
1. Import the folder (GitHub or Vercel CLI). `.env` is ignored on purpose.
2. Vercel > Settings > Environment Variables: paste the whole contents of `.env` (remove the PORT line if you like). Do not add quotes.
3. Deployments > Redeploy.
4. Test `https://YOUR-APP.vercel.app/api/resources`: it should say "Unauthorized". If it names missing variables, add them and redeploy.
5. Open `/admin`, upload, enter your site address, generate the QR code.

## Security
- Rotate the Supabase key if it was ever shared: Supabase > Settings > API Keys > create a new secret key, update Vercel, disable the old one.
- Use a stronger admin password than a name plus number.
- Files are private; each download uses a 60-second signed link.
