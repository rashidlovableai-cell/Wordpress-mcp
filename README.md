# Class Hub

Password-protected download page for your class. Students see only your name and a download list. You manage everything at `/admin`.

## 1. Supabase (one time)
1. Create a project at supabase.com.
2. Storage: create a **private** bucket named `class-files`.
3. SQL Editor: run

```sql
create table resources (
  id uuid primary key default gen_random_uuid(),
  title text not null,
  kind text not null check (kind in ('file','link')),
  url text,
  storage_path text,
  created_at timestamptz default now()
);
alter table resources enable row level security;
```
4. Project Settings > API: copy the Project URL and the `service_role` key.

## 2. Run locally
```
npm install
cp .env.example .env     # fill in your values
npm start
```
- Students: http://localhost:3000
- You: http://localhost:3000/admin

## 3. QR code
Phones can't open `localhost`. In `/admin`, enter the address students can reach (your laptop's Wi-Fi IP like `http://192.168.1.20:3000`, or a tunnel URL from `ngrok http 3000`), click **Generate QR code**, and show or save it.

## Security
- Passwords and the Supabase key stay on the server in `.env`. Never share the `service_role` key.
- Files are private; each download uses a 60-second signed link.
- Login is limited to 10 attempts per 15 minutes per device.

## Deploy on Vercel
1. Push the folder to GitHub and import it in Vercel (no build settings needed).
2. Project > Settings > Environment Variables: add all five values from `.env.example` (`SUPABASE_SERVICE_KEY` must be the **service_role** key). Then **Redeploy**.
3. Open `https://your-app.vercel.app/admin`, upload, then generate the QR code with that address.

## Troubleshooting
- **500 FUNCTION_INVOCATION_FAILED on Vercel**: environment variables missing or the project not redeployed after adding them. Open `/api/resources`; it now names any missing variable.
- **"Failed to fetch (api.supabase.com)" in the SQL Editor**: a network problem between your browser and Supabase, not a SQL error. Retry, disable ad blockers/VPN or try another network or DNS (1.1.1.1), and check status.supabase.com.
