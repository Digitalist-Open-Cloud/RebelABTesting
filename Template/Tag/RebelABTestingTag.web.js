(function () {
  return function (parameters, TagManager) {
      var KEY_PREFIX = "sabt:";
      var CACHE_PREFIX = "sabt-experiments:";
      var CACHE_SECONDS = 300;
      var PREVIEW_KEY = "sabt-preview";
      var SHOWN_PREFIX = "sabt-shown:";
      var VISIT_SECONDS = 1800;
      var FAKE_DOOR = "fake_door";
      var DOOR_VARIANT = 2;
      var DOOR_POSITIONS = { before: "beforebegin", after: "afterend", prepend: "afterbegin", append: "beforeend" };
      var ORIGINAL = 1;
      var NOT_TAKING_PART = 0;

      this.fire = function () {
          var origin = parameters.get("matomoOrigin");
          var container = parameters.get("container");
          var idSite = container && container.idsite;
          var hideTimeout = parseInt(parameters.get("hideTimeout", 500), 10) || 0;
          var _paq = (window._paq = window._paq || []);
          var cacheKey = CACHE_PREFIX + idSite;
          var cache;

          if (!origin || !idSite) return;

          var preview = getPreview();
          if (preview) {
              // Shows the variant of the link, whatever the state of the experiment. Nothing is
              // kept or sent, so the visit is not counted.
              var show = hide(hideTimeout);
              fetchExperiments(function (experiments) {
                  for (var i = 0; i < experiments.length; i++) {
                      if (String(experiments[i].id) !== preview[0]) continue;
                      if (experiments[i].kind === FAKE_DOOR) {
                          showDoor(experiments[i], false);
                      } else {
                          apply(experiments[i].variants[preview[1] - ORIGINAL - 1]);
                      }
                  }
                  show();
              }, show, "&preview=" + preview[0] + "-" + preview[2]);
              return;
          }

          // Without localStorage a visitor cannot keep a variant: everyone gets the original and
          // nobody is counted.
          try {
              cache = JSON.parse(localStorage.getItem(cacheKey));
          } catch (e) {
              return;
          }

          if (cache && Array.isArray(cache.e)) {
              // The experiments of an earlier page are used at once, so that nothing flashes.
              // A change in Matomo reaches the visitor on a page after the next fetch.
              run(cache.e, false);
              if (Date.now() - cache.t > CACHE_SECONDS * 1000) {
                  fetchExperiments(store);
              }
              return;
          }

          var reveal = hide(hideTimeout);
          fetchExperiments(function (experiments) {
              store(experiments);
              run(experiments, true);
              reveal();
          }, reveal);

          function store(experiments) {
              try {
                  localStorage.setItem(cacheKey, JSON.stringify({ t: Date.now(), e: experiments }));
              } catch (e) { /* the next page fetches again */ }
          }

          // A preview link ends with #rabt-preview=<experiment>-<variant>-<secret>. It lasts for
          // the tab, and #rabt-preview=off ends it.
          function getPreview() {
              var inAddress = /[#&]rabt-preview=([^&]*)/.exec(window.location.hash || "");
              var value = inAddress && inAddress[1];
              try {
                  if (value) sessionStorage.setItem(PREVIEW_KEY, value);
                  value = sessionStorage.getItem(PREVIEW_KEY);
              } catch (e) { /* the link works for this page only */ }

              var parts = /^(\d+)-(\d+)-([0-9a-f]{32})$/.exec(value || "");
              return parts ? [parts[1], Number(parts[2]), parts[3]] : null;
          }

          function fetchExperiments(done, failed, extra) {
              var url = origin + "/index.php?module=RebelABTesting&action=getExperimentsPublic&idSite=" + encodeURIComponent(idSite) + (extra || "");
              fetch(url, { method: "GET", credentials: "omit" })
                  .then(function (response) { return response.json(); })
                  .then(function (data) { done(data && Array.isArray(data.experiments) ? data.experiments : []); })
                  .catch(function () { if (failed) failed(); });
          }

          // Hides the page until reveal() is called or the time is up.
          function hide(milliseconds) {
              if (!milliseconds) return function () {};

              var style = document.createElement("style");
              style.textContent = "html{opacity:0 !important}";
              document.head.appendChild(style);

              var timer = setTimeout(reveal, milliseconds);
              function reveal() {
                  clearTimeout(timer);
                  style.remove();
              }
              return reveal;
          }

          function run(experiments, waited) {
              var today = new Date().toISOString().slice(0, 10);
              var isNew = false;

              removeExpiredAssignments(today);

              for (var i = 0; i < experiments.length; i++) {
                  var experiment = experiments[i];
                  if (today < experiment.from_date || today > experiment.to_date || !matches(experiment)) continue;

                  var assignment = getAssignment(experiment);
                  if (!assignment) return;
                  isNew = isNew || (assignment.isNew && experiment.kind !== FAKE_DOOR);

                  if (experiment.kind === FAKE_DOOR) {
                      if (assignment.v === DOOR_VARIANT) showDoor(experiment, true);
                  } else {
                      apply(experiment.variants[assignment.v - ORIGINAL - 1]);
                  }
              }

              var active = activeAssignments(today);
              if (!active) return;

              _paq.push(["appendToTrackingUrl", "rabt=" + active]);
              // The page view may already be sent, without the experiment.
              if (isNew || waited) _paq.push(["ping"]);
          }

          // Adds the button or link of a fake door, once the element it belongs to is there.
          // Showing it and clicking it are told to Matomo when the visitor is counted.
          function showDoor(experiment, counted) {
              var door = experiment.door;

              function place() {
                  var target;
                  try { target = document.querySelector(door.selector); } catch (e) { return true; }
                  if (!target) return false;

                  var element = document.createElement(door.element === "link" ? "a" : "button");
                  if (door.element === "link") element.href = "#"; else element.type = "button";
                  element.textContent = door.label;
                  if (door.css_class) element.className = door.css_class;
                  element.addEventListener("click", function (event) {
                      event.preventDefault();
                      var message = document.createElement("span");
                      message.setAttribute("role", "status");
                      message.textContent = door.message;
                      element.replaceWith(message);
                      if (counted) tellAboutDoor(experiment.id, "c");
                  });
                  target.insertAdjacentElement(DOOR_POSITIONS[door.position] || "afterend", element);

                  if (counted && !wasShownThisVisit(experiment.id)) tellAboutDoor(experiment.id, "s");
                  return true;
              }

              if (!place() && document.readyState === "loading") {
                  document.addEventListener("DOMContentLoaded", place);
              }
          }

          // A visit is told once that the door was shown, not on each of its pages.
          function wasShownThisVisit(id) {
              var key = SHOWN_PREFIX + id;
              try {
                  var shown = Date.now() - Number(localStorage.getItem(key)) < VISIT_SECONDS * 1000;
                  localStorage.setItem(key, String(Date.now()));
                  return shown;
              } catch (e) {
                  return false;
              }
          }

          function tellAboutDoor(id, what) {
              var active = activeAssignments(new Date().toISOString().slice(0, 10));
              _paq.push(["appendToTrackingUrl", "rabt=" + active + "&rabtd=" + id + "-" + what]);
              _paq.push(what === "c" ? ["trackEvent", "Fake door", "Click", String(id)] : ["ping"]);
              _paq.push(["appendToTrackingUrl", "rabt=" + active]);
          }

          // The highest variant a visitor can have. A fake door has no original: the visitors in
          // the experiment all get the door.
          function maxVariant(experiment) {
              return experiment.kind === FAKE_DOOR ? DOOR_VARIANT : experiment.variants.length + ORIGINAL;
          }

          // The original has no variant to apply.
          function apply(variant) {
              if (!variant) return;
              try {
                  insert("style", variant.css);
                  insert("script", variant.js);
              } catch (e) {
                  console.error("Error in the variant of an experiment", e);
              }
          }

          function matches(experiment) {
              var url = window.location.href;
              switch (experiment.match) {
                  case "equals": return url === experiment.value;
                  case "contains": return url.indexOf(experiment.value) !== -1;
                  case "starts_with": return url.indexOf(experiment.value) === 0;
                  case "regexp":
                      try { return new RegExp(experiment.value).test(url); } catch (e) { return false; }
                  default: return true;
              }
          }

          function isValid(record) {
              return record && typeof record.v === "number" && record.v >= NOT_TAKING_PART
                  && /^[0-9a-f]{32}$/.test(String(record.a)) && typeof record.exp === "string";
          }

          function read(key) {
              try { return JSON.parse(localStorage.getItem(key)); } catch (e) { return null; }
          }

          // The variant the visitor has in the experiment, drawn by chance the first time.
          // Null when it cannot be kept.
          function getAssignment(experiment) {
              var key = KEY_PREFIX + experiment.id;
              var record = read(key);
              var isNew = !isValid(record) || record.v > maxVariant(experiment);

              if (isNew) {
                  var takesPart = Math.random() * 100 < experiment.share;
                  var variant = experiment.kind === FAKE_DOOR
                      ? DOOR_VARIANT : ORIGINAL + Math.floor(Math.random() * (experiment.variants.length + 1));
                  record = { v: takesPart ? variant : NOT_TAKING_PART, a: randomHex32() };
              }

              try {
                  localStorage.setItem(key, JSON.stringify({ v: record.v, a: record.a, exp: experiment.to_date }));
              } catch (e) {
                  return null;
              }
              return { v: record.v, isNew: isNew && record.v !== NOT_TAKING_PART };
          }

          function assignmentKeys() {
              var keys = [];
              for (var i = 0; i < localStorage.length; i++) {
                  var key = localStorage.key(i);
                  if (key && key.indexOf(KEY_PREFIX) === 0) keys.push(key);
              }
              return keys;
          }

          function removeExpiredAssignments(today) {
              assignmentKeys().forEach(function (key) {
                  var record = read(key);
                  if (!isValid(record) || record.exp < today) localStorage.removeItem(key);
              });
          }

          // What is sent with tracking requests: "id-variant-assignment" of each experiment the
          // visitor takes part in.
          function activeAssignments(today) {
              return assignmentKeys().map(function (key) {
                  var record = read(key);
                  return isValid(record) && record.v >= ORIGINAL && record.exp >= today
                      ? key.substring(KEY_PREFIX.length) + "-" + record.v + "-" + record.a : null;
              }).filter(Boolean).join(",");
          }

          function randomHex32() {
              var bytes = crypto.getRandomValues(new Uint8Array(16));
              var out = "";
              for (var i = 0; i < bytes.length; i++) {
                  out += (bytes[i] < 16 ? "0" : "") + bytes[i].toString(16);
              }
              return out;
          }

          function insert(tag, text) {
              if (!text) return;
              var element = document.createElement(tag);
              element.textContent = text;
              document.head.appendChild(element);
          }
      };
  };
})();
