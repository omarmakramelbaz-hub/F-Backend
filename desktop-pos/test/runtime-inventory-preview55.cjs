'use strict';
// Frozen preview55 worker acceptance baseline. Execute only this disposable
// fixture's installed test code, never a retained production runtime file.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const vm = require('node:vm');
const { createRequire } = require('node:module');
const { Worker } = require('node:worker_threads');

const workerSha256 = 'e095aa4b3055bd207234fef306ceb027115e85fd06082574be1122b71a8aa539';
const parentInventorySha256 = '58b0e2df07ef72e3ef29b3b99653eccae03b861a2d2149ecaca3e6775207f5e3';
const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
let FrozenArchive;
module.exports = class Preview55Inventory {
  constructor(options = {}) {
    // Share the same parent class across warmup and measured instances, just
    // as the current implementation does; recompiling a VM per pass would
    // unfairly include a cold parent/JIT path in the preview55 measurements.
    if (FrozenArchive) return new FrozenArchive('/unused-preview55-benchmark-profile', {}, options);
    const file = path.resolve(__dirname, '../src/runtime-archive.cjs');
    const worker = path.resolve(__dirname, 'runtime-inventory-preview55-worker.cjs');
    assert.equal(sha(fs.readFileSync(worker)), workerSha256, 'The frozen preview55 worker bytes changed.');
    const localRequire = createRequire(file);
    class FrozenWorker extends Worker {
      constructor(target, settings) {
        assert.equal(path.resolve(target), path.resolve(__dirname, '../src/runtime-inventory-worker.cjs'));
        super(worker, settings);
      }
    }
    const module = { exports: {} };
    // Compile the trusted fixed parent in the SAME V8 realm. A separate VM
    // realm would make every structured-cloned array cross-realm and add a
    // large, unrelated validation cost to the preview55 side of the benchmark.
    const install = vm.runInThisContext('(function(exports,require,module,__filename,__dirname){\n'
      + fs.readFileSync(file, 'utf8') + '\n})', { filename: file });
    install(module.exports, name => name === 'node:worker_threads' ? { Worker: FrozenWorker } : localRequire(name),
      module, file, path.dirname(file));
    assert.equal(sha(module.exports.prototype.inventory.toString()), parentInventorySha256,
      'The original preview55 parent inventory protocol must remain byte-identical for this worker comparison.');
    FrozenArchive = module.exports;
    return new FrozenArchive('/unused-preview55-benchmark-profile', {}, options);
  }
};
module.exports.workerSha256 = workerSha256;
