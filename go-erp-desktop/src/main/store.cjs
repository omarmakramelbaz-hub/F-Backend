'use strict';
// Everything the app does with its own database: accounts, menus, invoices and the queue of operations to send.
// One Store, its methods kept by subject in ./store/ (the same methods, called the same way).
const crypto = require('node:crypto');
const { cleanTicketData } = require('./store/shared.cjs');

class Store {
  constructor(db, { now = () => new Date(), uuid = () => crypto.randomUUID() } = {}) {
    this.db = db;
    this.now = now;
    this.uuid = uuid;
  }
}

for (const part of ['accounts', 'sales', 'service', 'kitchen', 'customers', 'journal', 'attendance', 'reports', 'outbox']) {
  const methods = Object.getOwnPropertyDescriptors(require(`./store/${part}.cjs`));
  for (const name of Object.keys(methods)) {
    if (Object.prototype.hasOwnProperty.call(Store.prototype, name)) throw new Error(`Store.${name} defined twice`);
    methods[name].enumerable = false;
  }
  Object.defineProperties(Store.prototype, methods);
}

module.exports = { Store, cleanTicketData };
