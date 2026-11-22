// Puts Matomo and the experiments on the pages of the test website.
//
// With a container of Matomo Tag Manager, as on a real website:
//   index.html?mtm=/js/container_AbCd1234.js
// Without Tag Manager, the tracker and the tag of the plugin straight from this Matomo:
//   index.html?matomo=&idsite=1               Matomo at the root of this host
//   index.html?matomo=/tests/PHPUnit/proxy    Matomo's test environment
//
// The choice is kept for the browser tab, so that the links between the pages keep it.
(function () {
    var KEY = 'test-shop-setup';
    var query = new URLSearchParams(window.location.search);
    var setup = null;

    if (query.has('mtm') || query.has('matomo')) {
        setup = { mtm: query.get('mtm'), matomo: query.get('matomo'), idsite: query.get('idsite') || '1' };
        sessionStorage.setItem(KEY, JSON.stringify(setup));
    } else {
        try { setup = JSON.parse(sessionStorage.getItem(KEY)); } catch (e) { /* no setup yet */ }
    }

    function load(src) {
        var script = document.createElement('script');
        script.src = src;
        script.async = true;
        document.head.appendChild(script);
    }

    // Only files of this host are loaded: the page must not run a script that an address names.
    if (setup && /^\/(?:[A-Za-z0-9_-]+\/)*js\/container_[A-Za-z0-9_]+\.js$/.test(setup.mtm || '')) {
        window._mtm = window._mtm || [];
        window._mtm.push({ 'mtm.startTime': Date.now(), event: 'mtm.Start' });
        load(setup.mtm);
    } else if (setup && /^(?:\/[A-Za-z0-9_-]+)*$/.test(setup.matomo || '') && /^\d+$/.test(setup.idsite)) {
        var _paq = window._paq = window._paq || [];
        _paq.push(['setTrackerUrl', setup.matomo + '/matomo.php']);
        _paq.push(['setSiteId', setup.idsite]);
        _paq.push(['trackPageView']);
        load('/matomo.js');

        // What Tag Manager does with the tag of the plugin, when its trigger fires.
        fetch('../../Template/Tag/RebelExperimentsTag.web.js')
            .then(function (response) { return response.text(); })
            .then(function (source) {
                var Tag = new Function('return ' + source.trim().replace(/;$/, ''))();
                var parameters = {
                    matomoUrl: window.location.origin + setup.matomo,
                    container: { idsite: Number(setup.idsite) },
                    hideTimeout: 500
                };
                new Tag({ get: function (name, fallback) { return name in parameters ? parameters[name] : fallback; } }, {}).fire();
            });
    }

    // Shows what the tag keeps about this visitor, to see which variant a browser got.
    function showState() {
        var footer = document.getElementById('experiment-state');
        if (!footer) return;

        var lines = [setup ? 'Setup: ' + JSON.stringify(setup) : 'No setup. Open a page with ?mtm= or ?matomo= as described in site.js.'];
        for (var i = 0; i < localStorage.length; i++) {
            var key = localStorage.key(i);
            if (/^rexp:/.test(key)) lines.push(key + ' = ' + localStorage.getItem(key));
        }
        footer.textContent = lines.join('\n');
    }

    document.addEventListener('DOMContentLoaded', function () {
        showState();
        setInterval(showState, 1000);
    });
})();
