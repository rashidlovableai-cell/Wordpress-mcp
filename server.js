require('dotenv').config();
const express = require('express');
const crypto = require('crypto');
const QRCode = require('qrcode');
const { createClient } = require('@supabase/supabase-js');

const { SUPABASE_URL, SUPABASE_SERVICE_KEY, STUDENT_PASSWORD, ADMIN_PASSWORD, SESSION_SECRET, PORT = 3000 } = process.env;
const BUCKET = 'class-files';
const missing = ['SUPABASE_URL', 'SUPABASE_SERVICE_KEY', 'STUDENT_PASSWORD', 'ADMIN_PASSWORD', 'SESSION_SECRET'].filter(k => !process.env[k]);
let client;
const db = () => client || (client = createClient(SUPABASE_URL, SUPABASE_SERVICE_KEY));
const app = express();
app.disable('x-powered-by');
app.set('trust proxy', 1);
app.use(express.json());
app.use(express.static('public'));
app.use((req, res, next) => missing.length
  ? res.status(500).json({ error: 'Server not configured. Missing: ' + missing.join(', ') }) : next());
app.get('/admin', (req, res) => res.sendFile(__dirname + '/public/admin.html'));

// --- auth: signed, HTTP-only cookies; passwords stay on the server ---
const sign = s => crypto.createHmac('sha256', SESSION_SECRET).update(s).digest('hex');
const same = (a, b) => { const x = Buffer.from(a), y = Buffer.from(b); return x.length === y.length && crypto.timingSafeEqual(x, y); };
const issue = (req, res, role) => {
  const p = `${role}.${Date.now() + 8 * 3600e3}`;
  res.setHeader('Set-Cookie', `${role}=${p}.${sign(p)}; HttpOnly; SameSite=Strict; Path=/; Max-Age=28800${req.secure ? '; Secure' : ''}`);
};
const cookie = (req, n) => ((req.headers.cookie || '').split('; ').find(c => c.startsWith(n + '=')) || '').slice(n.length + 1);
const guard = role => (req, res, next) => {
  const [r, exp, sig] = cookie(req, role).split('.');
  if (r === role && sig && +exp > Date.now() && same(sig, sign(`${r}.${exp}`))) return next();
  res.status(401).json({ error: 'Unauthorized' });
};
const tries = {};
const login = (role, pw) => (req, res) => {
  const t = tries[req.ip] = tries[req.ip] || { n: 0, reset: Date.now() + 15 * 60e3 };
  if (Date.now() > t.reset) { t.n = 0; t.reset = Date.now() + 15 * 60e3; }
  if (t.n >= 10) return res.status(429).json({ error: 'Too many attempts. Try again in 15 minutes.' });
  if (same(String(req.body.password || ''), pw)) { issue(req, res, role); return res.json({ ok: true }); }
  t.n++; res.status(401).json({ error: 'Wrong password' });
};
app.post('/api/login', login('student', STUDENT_PASSWORD));
app.post('/api/admin/login', login('admin', ADMIN_PASSWORD));

// --- students: titles + download buttons only ---
app.get('/api/resources', guard('student'), async (req, res) => {
  const { data, error } = await db().from('resources').select('id,title,kind').order('created_at', { ascending: false });
  error ? res.status(500).json({ error: error.message }) : res.json(data);
});
app.get('/api/download/:id', guard('student'), async (req, res) => {
  const { data: r } = await db().from('resources').select('*').eq('id', req.params.id).single();
  if (!r) return res.sendStatus(404);
  if (r.kind === 'link') return res.redirect(r.url);
  const { data, error } = await db().storage.from(BUCKET).createSignedUrl(r.storage_path, 60, { download: true });
  error ? res.status(500).send('Download failed') : res.redirect(data.signedUrl);
});

// --- admin: upload, list, delete, QR ---
app.get('/api/admin/resources', guard('admin'), async (req, res) => {
  const { data, error } = await db().from('resources').select('id,title,kind,url').order('created_at', { ascending: false });
  error ? res.status(500).json({ error: error.message }) : res.json(data);
});
app.post('/api/admin/upload-url', guard('admin'), async (req, res) => {
  const path = `${Date.now()}-${String(req.body.filename || 'file').replace(/[^\w.\-]/g, '_')}`;
  const { data, error } = await db().storage.from(BUCKET).createSignedUploadUrl(path);
  error ? res.status(500).json({ error: error.message }) : res.json({ url: data.signedUrl, path: data.path || path });
});
app.post('/api/admin/resources', guard('admin'), async (req, res) => {
  const { kind, storage_path, url } = req.body;
  const title = String(req.body.title || '').trim();
  if (!title) return res.status(400).json({ error: 'Add a title.' });
  let row;
  if (kind === 'file' && storage_path) row = { title, kind, storage_path };
  else if (kind === 'link' && /^https?:\/\//i.test(url || '')) row = { title, kind, url: url.trim() };
  else return res.status(400).json({ error: 'Choose a file or paste a link starting with http(s)://' });
  const { error } = await db().from('resources').insert(row);
  error ? res.status(500).json({ error: error.message }) : res.json({ ok: true });
});
app.delete('/api/admin/resources/:id', guard('admin'), async (req, res) => {
  const { data: r } = await db().from('resources').select('*').eq('id', req.params.id).single();
  if (r?.storage_path) await db().storage.from(BUCKET).remove([r.storage_path]);
  await db().from('resources').delete().eq('id', req.params.id);
  res.json({ ok: true });
});
app.get('/api/admin/qr', guard('admin'), async (req, res) =>
  res.json({ png: await QRCode.toDataURL(String(req.query.url || ''), { width: 600, margin: 2 }) }));

if (require.main === module) app.listen(PORT, '0.0.0.0', () => console.log(`Class Hub running: http://localhost:${PORT}  (admin: /admin)`));
module.exports = app;
