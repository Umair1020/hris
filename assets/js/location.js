/* Shared attendance location capture. Never substitute an IP-based location. */
function getLocation(cb) {
  var done = false;
  function finish(loc, message) {
    if (done) return;
    done = true;
    cb(loc, message || '');
  }
  if (window.isSecureContext === false) {
    finish(null, 'Location needs a secure connection. Open this page using HTTPS.');
    return;
  }
  if (!navigator.geolocation) {
    finish(null, 'Location is not supported here. Open this page in Chrome or Safari.');
    return;
  }
  function failure(err, fallback) {
    if (done) return;
    if (err && err.code === 1) {
      finish(null, 'Location permission is blocked. Allow Location for this website in browser settings, and enable location access for your browser in phone settings. If using an in-app browser, open this page directly in Chrome or Safari, then try again.');
    } else if (!fallback) {
      request(true);
    } else {
      finish(null, 'Could not get your location. Enable phone Location Services and precise location for your browser, check Wi-Fi/mobile data, move near a window or outdoors, then try again.');
    }
  }
  function request(fallback) {
    try {
      navigator.geolocation.getCurrentPosition(function (pos) {
        var c = pos.coords;
        if (!c || !Number.isFinite(c.latitude) || !Number.isFinite(c.longitude) || Math.abs(c.latitude) > 90 || Math.abs(c.longitude) > 180) {
          failure(null, fallback);
          return;
        }
        finish(c.latitude + ',' + c.longitude);
      }, function (err) { failure(err, fallback); }, {
        enableHighAccuracy: !fallback,
        timeout: fallback ? 15000 : 25000,
        maximumAge: 0
      });
    } catch (err) { failure(err, fallback); }
  }
  request(false);
}
