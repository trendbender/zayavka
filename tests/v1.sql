-- database of 0.1.0 with two leads, for the upgrade test
PRAGMA journal_mode=WAL;
CREATE TABLE leads(id INTEGER PRIMARY KEY AUTOINCREMENT, idem TEXT UNIQUE NOT NULL, form TEXT NOT NULL, page TEXT NOT NULL, fields TEXT NOT NULL, consent_id TEXT NOT NULL, consent_sha TEXT NOT NULL, consent_at TEXT NOT NULL, created_at INTEGER NOT NULL, suspect INTEGER NOT NULL DEFAULT 0);
CREATE TABLE consents(sha TEXT PRIMARY KEY, id TEXT NOT NULL, text TEXT NOT NULL, first_seen TEXT NOT NULL);
CREATE TABLE deliveries(id INTEGER PRIMARY KEY, lead_id INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE, channel TEXT NOT NULL, target TEXT NOT NULL, declared INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'pending', tries INTEGER NOT NULL DEFAULT 0, next_at INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '', UNIQUE(lead_id, channel));
CREATE INDEX deliveries_due ON deliveries(status, next_at);
CREATE TABLE hits(h TEXT NOT NULL, exp INTEGER NOT NULL); CREATE INDEX hits_h ON hits(h, exp);
CREATE TABLE counters(day TEXT NOT NULL, k TEXT NOT NULL, n INTEGER NOT NULL, PRIMARY KEY(day, k));
CREATE TABLE state(k TEXT PRIMARY KEY, v TEXT NOT NULL);
CREATE TABLE audit(ts INTEGER NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL, lead TEXT NOT NULL DEFAULT '', info TEXT NOT NULL DEFAULT '');
INSERT INTO leads(idem,form,page,fields,consent_id,consent_sha,consent_at,created_at) VALUES('a','callback','','{}','v1','x','x',strftime('%s','now')),('b','callback','','{}','v1','x','x',strftime('%s','now'));
INSERT INTO deliveries(id,lead_id,channel,target,declared,status) VALUES(1,1,'w','t',0,'sent'),(2,2,'w','t',0,'pending');
UPDATE deliveries SET next_at=4000000000 WHERE id=2;
PRAGMA user_version=1;
