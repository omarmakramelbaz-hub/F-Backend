'use strict';
// Store: The customer directory (delivery customers and their addresses).
const { transaction } = require('../db.cjs');

module.exports = {
  // ---- the branches' saved delivery customers (new delivery orders offline) ----

  customersCursor() {
    return this.get('customers_cursor', null);
  },

/** A page of the dashboard's saved customers changed since the device's cursor. Returns whether more are waiting. */
  applyCustomers(page) {
    if (!page || !Array.isArray(page.items)) return false;
    transaction(this.db, () => {
      const upsert = this.db.prepare(`INSERT INTO directory (id, branch, name, phone, phone_key, address, area, delivery_notes, latitude, longitude, revision,
        local, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?) ON CONFLICT(id) DO UPDATE SET branch = excluded.branch, name = excluded.name,
        phone = excluded.phone, phone_key = excluded.phone_key, address = excluded.address, area = excluded.area, delivery_notes = excluded.delivery_notes,
        latitude = excluded.latitude, longitude = excluded.longitude, revision = excluded.revision, local = 0, updated_at = excluded.updated_at`);
      const stamp = this.now().toISOString();
      for (const item of page.items) {
        upsert.run(item.id, item.branch, item.name || '', item.phone || '', item.phone_key || '', item.address || '', item.area || '', item.delivery_notes || '',
          item.latitude ?? null, item.longitude ?? null, item.revision || 1, stamp);
        // Saved here before: the dashboard's record (same number) now stands for it.
        this.db.prepare('DELETE FROM directory WHERE local = 1 AND branch = ? AND phone_key = ?').run(item.branch, item.phone_key || '');
      }
      if (page.cursor) this.set('customers_cursor', page.cursor);
    });
    return !!page.more;
  },

/** Saved customers of the branch with one of these numbers (or starting with them). */
  directoryByPhone(branch, keys, prefix) {
    if (!keys.length) return [];
    const where = keys.map(() => (prefix ? 'phone_key LIKE ?' : 'phone_key = ?')).join(' OR ');
    return this.db.prepare(`SELECT * FROM directory WHERE branch = ? AND (${where}) ORDER BY local DESC, updated_at DESC, id DESC LIMIT 20`)
      .all(branch, ...keys.map((key) => (prefix ? key.replace(/[%_]/g, '') + '%' : key)));
  },

/** The branch's saved addresses with a location (the delivery page's saved-address search). */
  directoryAddresses(branch) {
    return this.db.prepare('SELECT address, area, latitude, longitude FROM directory WHERE branch = ? AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY id')
      .all(branch);
  },

/** A delivery customer saved on the dashboard's page offline: kept here and sent (customer_save). */
  saveDirectoryCustomer(actor, values) {
    return this.atomically(() => {
      const stamp = this.now().toISOString();
      const existing = values.customer_id ? this.db.prepare('SELECT * FROM directory WHERE id = ? AND branch = ?').get(values.customer_id, values.branch)
        : this.db.prepare('SELECT * FROM directory WHERE branch = ? AND phone_key = ? ORDER BY local DESC LIMIT 1').get(values.branch, values.phone_key);
      const id = existing ? existing.id : 4000000000000 + this.nextCounter('directory_seq');
      const revision = existing ? existing.revision + 1 : 1;
      this.db.prepare(`INSERT INTO directory (id, branch, name, phone, phone_key, address, area, delivery_notes, latitude, longitude, revision, local, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?) ON CONFLICT(id) DO UPDATE SET name = excluded.name, phone = excluded.phone, phone_key = excluded.phone_key,
        address = excluded.address, area = excluded.area, delivery_notes = excluded.delivery_notes, latitude = excluded.latitude, longitude = excluded.longitude,
        revision = excluded.revision, local = 1, updated_at = excluded.updated_at`)
        .run(id, values.branch, values.name, values.phone, values.phone_key, values.address, values.area, values.delivery_notes, values.latitude ?? null,
          values.longitude ?? null, revision, stamp);
      this.db.prepare(`INSERT INTO outbox (id, kind, ticket_id, payload, status, created_at, updated_at) VALUES (?, 'customer_save', NULL, ?, 'pending', ?, ?)`)
        .run(values.key, JSON.stringify({ id: values.key, kind: 'customer_save', actor_id: actor.id, branch: values.branch, occurred_at: stamp,
          data: { name: values.name, phone: values.phone, address: values.address, area: values.area, delivery_notes: values.delivery_notes,
            latitude: values.latitude ?? null, longitude: values.longitude ?? null } }), stamp, stamp);
      return this.db.prepare('SELECT * FROM directory WHERE id = ?').get(id);
    });
  },
};
