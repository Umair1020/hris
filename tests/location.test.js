const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = readFileSync('assets/js/location.js', 'utf8');
function run(secure, geo) {
  let result, calls = 0;
  const ctx = {window: {isSecureContext: secure}, navigator: {geolocation: geo}};
  vm.createContext(ctx);
  vm.runInContext(source, ctx);
  ctx.getLocation((loc, error) => { result = {loc, error}; calls++; });
  assert.equal(calls, 1);
  return result;
}
assert.match(run(false, {}).error, /HTTPS/);
assert.match(run(true, null).error, /not supported/);
let attempts = 0;
assert.match(run(true, {getCurrentPosition(ok, fail) { attempts++; fail({code: 1}); }}).error, /permission/);
assert.equal(attempts, 1);
attempts = 0;
assert.equal(run(true, {getCurrentPosition(ok, fail, opts) {
  attempts++;
  assert.equal(opts.maximumAge, 0);
  if (attempts === 1) { assert.equal(opts.enableHighAccuracy, true); fail({code: 3}); }
  else { assert.equal(opts.enableHighAccuracy, false); ok({coords: {latitude: 24.86, longitude: 67.01}}); }
}}).loc, '24.86,67.01');
assert.equal(attempts, 2);
assert.match(run(true, {getCurrentPosition(ok, fail) { fail({code: 2}); }}).error, /Location Services/);
assert.equal(run(true, {getCurrentPosition(ok) { ok({coords: {latitude: 0, longitude: 0}}); }}).loc, '0,0');
assert.equal(run(true, {getCurrentPosition(ok) { ok({coords: {latitude: NaN, longitude: 181}}); }}).loc, null);
console.log('Location tests passed');
