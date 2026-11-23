(function () {
  var KEY_PREFIX = 'rexp:';

  // The experiments the visitor takes part in, as the tag of the plugin keeps them in
  // localStorage: "id-variant-assignment" of each, separated by commas.
  function activeAssignments() {
      var active = [];
      try {
          var today = new Date().toISOString().slice(0, 10);
          for (var i = 0; i < window.localStorage.length; i++) {
              var key = window.localStorage.key(i);
              if (!key || key.indexOf(KEY_PREFIX) !== 0) {
                  continue;
              }
              var record;
              try {
                  record = JSON.parse(window.localStorage.getItem(key));
              } catch (e) {
                  continue;
              }
              if (record && typeof record.v === 'number' && record.v >= 1
                  && /^[0-9a-f]{32}$/.test(String(record.a))
                  && typeof record.exp === 'string' && record.exp >= today) {
                  active.push(key.substring(KEY_PREFIX.length) + '-' + record.v + '-' + record.a);
              }
          }
      } catch (e) {
          // localStorage is blocked
      }
      return active.join(',');
  }

  function trackingParams() {
      var active = activeAssignments();
      return active ? '&rexp=' + active : '';
  }

  // Every kind of request gets the parameter, so that a goal, event or order without a new page
  // view counts for the experiment too.
  var HOOKS = ['log', 'link', 'goal', 'event', 'sitesearch', 'ecommerce', 'ping',
      'contentImpression', 'contentInteraction'];

  function init() {
      var plugin = {};
      for (var i = 0; i < HOOKS.length; i++) {
          plugin[HOOKS[i]] = trackingParams;
      }
      Matomo.addPlugin('RebelExperiments', plugin);
  }

  if ('object' === typeof window.Matomo) {
      init();
  } else {
      if ('object' !== typeof window.matomoPluginAsyncInit) {
          window.matomoPluginAsyncInit = [];
      }
      window.matomoPluginAsyncInit.push(init);
  }
})();
