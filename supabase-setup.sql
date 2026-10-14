-- Run once in Supabase: SQL Editor > New query > paste > Run
create table if not exists resources (
  id uuid primary key default gen_random_uuid(),
  title text not null,
  kind text not null check (kind in ('file','link')),
  url text,
  storage_path text,
  created_at timestamptz default now()
);
alter table resources enable row level security;

-- Private storage bucket for uploaded files
insert into storage.buckets (id, name, public)
values ('class-files', 'class-files', false)
on conflict (id) do nothing;
