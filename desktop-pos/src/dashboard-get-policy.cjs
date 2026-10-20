'use strict';
// Original routes/controllers with known mutations or consuming print grants.
// Ordinary central browsing is additionally marked dirty before transmission.
function normalized(path){
  // Laravel's route matcher uses rawurldecode byte semantics. A malformed
  // suffix must not hide an encoded ASCII admin prefix from this guard.
  return String(path).replace(/%([a-f0-9]{2})/gi,(_escape,byte)=>String.fromCharCode(parseInt(byte,16)))
    .replace(/\/{2,}/g,'/').replace(/\/+$/,'');
}
const admin=path=>{const value=normalized(path);return value==='/admin'||value.startsWith('/admin/');};
function mutation(path){
  path=normalized(path);
  return ['/clear-cache','/clear-compiled','/linkstoragse','/send_order_email','/test-test','/clear-cache-admin','/test-notification',
    '/admin/clear-cache-admin','/admin/test-notification','/admin/resturantControl','/admin/print-pdf','/admin/download-pdf'].includes(path)
    ||/^\/admin\/(?:change-status\/[^/]+|users\/[^/]+\/go_drive_activation|cancel\/order\/[^/]+\/delegate)$/.test(path)
    ||/^\/admin\/resturant_products\/[^/]+\/update\/(?:status|highest_rated)$/.test(path)
    ||/^\/admin\/order-board\/(?:legacy|store|service|partner_service)\/[^/]+\/print$/.test(path)
    ||/^\/admin\/takeaway\/receipts\/[^/]+\/print$/.test(path)
    ||/^\/admin\/(?:dining|phone-orders)\/(?:tickets|kitchen)\/[^/]+\/print$/.test(path)
    ||/^\/admin\/phone-orders\/batches\/[^/]+\/print$/.test(path)
    ||/^\/admin\/branch-shifts\/[^/]+\/print$/.test(path)
    ||/^\/admin\/branch-shift-print\/[^/]+\/receipt$/.test(path);
}
module.exports={mutation,admin,normalized};
