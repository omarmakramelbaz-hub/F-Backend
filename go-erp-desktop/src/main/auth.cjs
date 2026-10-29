'use strict';
// Signing in works without the internet: the dashboard password hash downloaded at the last sync is checked here.
const bcrypt = require('bcryptjs');

function verifyPassword(password, hash) {
  if (typeof password !== 'string' || typeof hash !== 'string' || !hash.startsWith('$2')) return false;
  // Laravel writes $2y$ hashes; $2b$ is the same algorithm under another name.
  return bcrypt.compareSync(password, hash.replace(/^\$2y\$/, '$2b$'));
}

function login(store, identifier, password) {
  const user = store.findUserByLogin(identifier);
  if (!user || !verifyPassword(password, user.password_hash)) {
    throw new Error('رقم الموبايل أو البريد أو كلمة المرور غير صحيحة. لو غيّرت كلمة المرور حديثًا، اتصل بالإنترنت لمزامنة الحسابات.');
  }
  delete user.password_hash;
  if (!user.branches.length) throw new Error('هذا الحساب ليس له فروع على هذا الجهاز.');
  return user;
}

module.exports = { verifyPassword, login };
