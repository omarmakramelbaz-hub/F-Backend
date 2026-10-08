'use strict';

/** Repository backup names must never turn production credentials into desktop resources. */
function excludedPath(file) {
  return /^(?:bootstrap\/cache\/|public\/(?:storage|storage1)(?:\/|$))/i.test(file)
    || /(?:^|\/)(?:\.env(?:[._-].*)?|[^/]*(?:firebase[^/]*credentials|credentials[^/]*firebase|service[-_]account)[^/]*|firebase\.json(?:[._-].*)?|[^/]+\.(?:key|pem)(?:[._-].*)?|error_log(?:[._-].*)?)$/i.test(file);
}

function credentialJson(file, bytes) {
  if (!/\.json(?:[._-][^/]*)?$/i.test(file)) return false;
  let data;
  try { data = JSON.parse(bytes.toString('utf8')); } catch { return false; }
  const inspect = value => {
    if (!value || typeof value !== 'object') return false;
    if (value.type === 'service_account') return true;
    for (const [key, item] of Object.entries(value)) {
      if (/^(?:private_key|private_key_id|client_secret|refresh_token|access_token)$/i.test(key) && typeof item === 'string' && item.length) return true;
      if (inspect(item)) return true;
    }
    return false;
  };
  return inspect(data);
}

module.exports = { excludedPath, credentialJson };
