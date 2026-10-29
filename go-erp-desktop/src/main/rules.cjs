'use strict';
// Dashboard rules the device must apply while offline, copied from the server so a sale is never refused later.

const ARABIC_DIGITS = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
  '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9' };

/** CustomerDirectory::normalizePhone: returns an international number or null. */
function normalizePhone(value) {
  let phone = String(value ?? '').trim().replace(/[٠-٩۰-۹]/g, (digit) => ARABIC_DIGITS[digit]);
  if (phone === '' || /[^+0-9\s().\-]/u.test(phone)) return null;
  phone = phone.replace(/[\s().\-]+/gu, '');
  if (phone.startsWith('00')) phone = '+' + phone.slice(2);
  if (/^01[0125][0-9]{8}$/.test(phone)) phone = '+20' + phone.slice(1);
  else if (/^1[0125][0-9]{8}$/.test(phone)) phone = '+20' + phone;
  else if (/^0[2-9][0-9]{7,8}$/.test(phone)) phone = '+20' + phone.slice(1);
  else if (/^[1-9][0-9]{7,14}$/.test(phone)) phone = '+' + phone;
  if (!/^\+[1-9][0-9]{7,14}$/.test(phone)) return null;
  if (phone.startsWith('+20') && !/^\+20(?:1[0125][0-9]{8}|[2-9][0-9]{7,8})$/.test(phone)) return null;
  return phone;
}

/** Dashboard login identity: mobile without its leading zero, or email. */
function loginKey(value) {
  let login = String(value ?? '').trim();
  if (login.startsWith('0')) login = login.slice(1);
  return login.toLowerCase();
}

const CAIRO = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Cairo', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', hourCycle: 'h23' });

/** OperatingDay::date: Cairo wall time, a day runs from 06:00 to 06:00. */
function operatingDay(date = new Date()) {
  const parts = Object.fromEntries(CAIRO.formatToParts(date).map((part) => [part.type, part.value]));
  if (Number(parts.hour) >= 6) return `${parts.year}-${parts.month}-${parts.day}`;
  const previous = new Date(date.getTime() - 24 * 3600 * 1000);
  const before = Object.fromEntries(CAIRO.formatToParts(previous).map((part) => [part.type, part.value]));
  return `${before.year}-${before.month}-${before.day}`;
}

module.exports = { normalizePhone, loginKey, operatingDay };
