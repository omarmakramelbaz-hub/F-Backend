const {DatabaseSync}=require('node:sqlite');
const {randomUUID}=require('node:crypto');
const Domain=require('./domain.js');

class Store {
  constructor(file) {
    this.db=new DatabaseSync(file);
    this.db.exec(`PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;
      CREATE TABLE IF NOT EXISTS meta(key TEXT PRIMARY KEY,value TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS snapshots(id TEXT PRIMARY KEY,payload TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS orders(id TEXT PRIMARY KEY,snapshot_id TEXT NOT NULL,status TEXT NOT NULL,revision INTEGER NOT NULL DEFAULT 0,data TEXT NOT NULL,updated_at TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS outbox(seq INTEGER PRIMARY KEY AUTOINCREMENT,id TEXT NOT NULL UNIQUE,order_id TEXT NOT NULL,payload TEXT NOT NULL,acked INTEGER NOT NULL DEFAULT 0,result TEXT,last_error TEXT,attempts INTEGER NOT NULL DEFAULT 0,printed INTEGER NOT NULL DEFAULT 0);
    `);
  }
  tx(fn) {this.db.exec('BEGIN IMMEDIATE');try{const r=fn();this.db.exec('COMMIT');return r;}catch(e){this.db.exec('ROLLBACK');throw e;}}
  get(key) {const r=this.db.prepare('SELECT value FROM meta WHERE key=?').get(key);return r?JSON.parse(r.value):null;}
  set(key,value) {this.db.prepare('INSERT INTO meta VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value').run(key,JSON.stringify(value));}
  saveSnapshot(snapshot) {this.tx(()=>{this.db.prepare('INSERT OR IGNORE INTO snapshots VALUES(?,?)').run(snapshot.id,JSON.stringify(snapshot));this.set('latest_snapshot',snapshot.id);});}
  snapshot(id) {const r=this.db.prepare('SELECT payload FROM snapshots WHERE id=?').get(id||this.get('latest_snapshot'));return r?JSON.parse(r.payload):null;}
  newOrder() {
    const snap=this.snapshot();if(!snap) throw Error('يلزم ربط الجهاز وتنزيل المينيو أول مرة بالإنترنت.');
    const id=randomUUID(), data={channel:'takeaway',items:[],delivery_cents:0,discount:'0.00',discount_reason:'',cash_received:'0.00',customer_name:'',customer_phone:'',address:'',table_name:'',notes:''};
    this.db.prepare('INSERT INTO orders VALUES(?,?,?,?,?,?)').run(id,snap.id,'draft',0,JSON.stringify(data),new Date().toISOString());return this.order(id);
  }
  order(id) {const row=this.db.prepare('SELECT * FROM orders WHERE id=?').get(id);if(!row)throw Error('الطلب غير موجود.');return {...row,data:JSON.parse(row.data),snapshot:this.snapshot(row.snapshot_id)};}
  discard(id) {this.tx(()=>{const o=this.order(id);if(o.revision!==0||o.status!=='draft'||o.data.items.length)throw Error('الحذف متاح للمسودة الفارغة فقط.');this.db.prepare('DELETE FROM orders WHERE id=?').run(id);});}
  update(id,input) {
    return this.tx(()=>{
      const o=this.order(id); if(['paid','cancelled'].includes(o.status))throw Error('الفاتورة منتهية.');
      const d=Domain.clean(input); Domain.quote(o.snapshot,d);
      if(o.revision>0&&d.channel!==o.data.channel)throw Error('لا يمكن تغيير نوع طلب محفوظ.');
      if(o.status==='bill') {const a={...o.data},b={...d};delete a.cash_received;delete b.cash_received;if(JSON.stringify(a)!==JSON.stringify(b))throw Error('تمت طباعة الحساب؛ محتويات الفاتورة ثابتة.');}
      this.db.prepare('UPDATE orders SET data=?,updated_at=? WHERE id=?').run(JSON.stringify(d),new Date().toISOString(),id);return this.order(id);
    });
  }
  dispatch(id,kind,reason='') {
    return this.tx(()=>{
      const o=this.order(id), data={...o.data};if(kind==='cancel')data.cancel_reason=reason;
      const status=Domain.validateAction(o.status,kind,data,o.snapshot), q=Domain.quote(o.snapshot,data);
      const event={id:randomUUID(),order_id:id,snapshot_id:o.snapshot_id,kind,revision:o.revision+1,occurred_at:new Date().toISOString(),data:{...data,total_cents:q.total_cents}};
      // The order update and upload record commit together, before a printer is called.
      this.db.prepare('INSERT INTO outbox(id,order_id,payload) VALUES(?,?,?)').run(event.id,id,JSON.stringify(event));
      this.db.prepare('UPDATE orders SET status=?,revision=?,updated_at=? WHERE id=?').run(status,event.revision,event.occurred_at,id);
      return event;
    });
  }
  pending() {return this.db.prepare('SELECT * FROM outbox WHERE acked=0 ORDER BY seq LIMIT 50').all().map(r=>({...r,event:JSON.parse(r.payload)}));}
  acknowledge(id,result) {if(result.id!==id)throw Error('لم يؤكد السيرفر رقم العملية الصحيح.');this.db.prepare('UPDATE outbox SET acked=1,result=?,last_error=NULL WHERE id=?').run(JSON.stringify(result),id);}
  fail(id,error) {this.db.prepare('UPDATE outbox SET attempts=attempts+1,last_error=? WHERE id=?').run(String(error).slice(0,1000),id);}
  counts() {return this.db.prepare('SELECT COUNT(*) AS pending, SUM(CASE WHEN last_error IS NOT NULL THEN 1 ELSE 0 END) AS errors FROM outbox WHERE acked=0').get();}
  openOrders() {return this.db.prepare("SELECT id,status,revision,data,updated_at FROM orders WHERE status NOT IN ('paid','cancelled') ORDER BY updated_at DESC").all().map(r=>({...r,data:JSON.parse(r.data)}));}
  history() {return this.db.prepare('SELECT id,order_id,payload,acked,printed,last_error,result FROM outbox ORDER BY seq DESC LIMIT 100').all().map(r=>({...r,event:JSON.parse(r.payload),result:r.result?JSON.parse(r.result):null}));}
  event(id) {const r=this.db.prepare('SELECT payload FROM outbox WHERE id=?').get(id);if(!r)throw Error('العملية غير موجودة.');return JSON.parse(r.payload);}
  markPrinted(id) {this.db.prepare('UPDATE outbox SET printed=printed+1 WHERE id=?').run(id);}
  close() {if(this.db.isOpen)this.db.close();}
}
module.exports=Store;
