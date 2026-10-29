// Every action goes through the main process; the screens hold no data of their own.
export function call(channel, values) {
  return window.goerp.call(channel, values);
}

export function onSyncStatus(callback) {
  return window.goerp.onSyncStatus(callback);
}

export function money(cents) {
  const value = Math.abs(Number(cents) || 0);
  const text = Math.floor(value / 100).toLocaleString('en-US') + '.' + String(value % 100).padStart(2, '0');
  return (cents < 0 ? '-' : '') + text;
}

export function time(iso) {
  if (!iso) return '';
  return new Intl.DateTimeFormat('ar-EG-u-nu-latn', { timeZone: 'Africa/Cairo', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}

export function dateTime(iso) {
  if (!iso) return '';
  return new Intl.DateTimeFormat('ar-EG-u-nu-latn', { timeZone: 'Africa/Cairo', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
}

export function since(iso) {
  if (!iso) return 'لم تتم بعد';
  const seconds = Math.max(0, Math.round((Date.now() - Date.parse(iso)) / 1000));
  if (seconds < 60) return 'منذ ثوانٍ';
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `منذ ${minutes} دقيقة`;
  return dateTime(iso);
}

export const CHANNELS = { takeaway: 'تيك أواي', dine: 'صالة', phone: 'دليفري' };
