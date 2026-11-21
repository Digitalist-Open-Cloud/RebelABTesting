// Runs the tag and the tracker plugin in a faked browser.
const root = new URL("../../", import.meta.url);

export function today(offsetDays = 0) {
  return new Date(Date.now() + offsetDays * 86400000).toISOString().slice(0, 10);
}

export function makeStorage(initial = {}, opts = {}) {
  const m = new Map(Object.entries(initial));
  return {
    get length() { return m.size; },
    key(i) { return Array.from(m.keys())[i] ?? null; },
    getItem(k) { if (opts.throwOnGet) throw new Error("blocked"); return m.has(k) ? m.get(k) : null; },
    setItem(k, v) { if (opts.throwOnSet) throw new Error("quota"); m.set(k, String(v)); },
    removeItem(k) { m.delete(k); },
  };
}

export function makeEnv(opts = {}) {
  const env = {
    appended: [],
    fetchCalls: [],
    localStorage: opts.localStorage ?? makeStorage(),
    _paq: [],
    crypto: {
      getRandomValues(a) { for (let i = 0; i < a.length; i++) a[i] = (i * 17 + 3) & 255; return a; },
    },
    experiments: opts.experiments ?? [],
    fetchFails: opts.fetchFails ?? false,
  };
  env.document = {
    head: { appendChild(n) { env.appended.push(n); } },
    createElement(tag) {
      const node = { tagName: tag, remove() { env.appended = env.appended.filter((n) => n !== node); } };
      return node;
    },
  };
  env.fetch = (url) => {
    env.fetchCalls.push(url);
    return env.fetchFails
      ? Promise.reject(new Error("offline"))
      : Promise.resolve({ json: () => Promise.resolve({ experiments: env.experiments }) });
  };
  env.window = {
    _paq: env._paq,
    localStorage: env.localStorage,
    location: { href: opts.url ?? "https://shop.example/pricing" },
  };
  return env;
}

export function loadTag(env, params = {}) {
  const src = Deno.readTextFileSync(new URL("Template/Tag/RebelABTestingTag.web.js", root));
  const Tag = new Function("window", "document", "localStorage", "crypto", "fetch", "return " + src.trim().replace(/;$/, ""))(
    env.window, env.document, env.localStorage, env.crypto, env.fetch);
  const all = { matomoOrigin: "https://m.example", container: { idsite: 1 }, hideTimeout: 500, ...params };
  const tag = new Tag({ get: (name, fallback) => (name in all ? all[name] : fallback) }, {});
  return {
    async fire() {
      tag.fire();
      // lets the answer of the fetch arrive
      await new Promise((resolve) => setTimeout(resolve, 0));
    },
  };
}

export function loadTracker(env, { matomoReady = true } = {}) {
  let plugin;
  const Matomo = { addPlugin(_name, p) { plugin = p; } };
  const asyncInit = [];
  if (matomoReady) env.window.Matomo = Matomo;
  env.window.matomoPluginAsyncInit = asyncInit;
  const src = Deno.readTextFileSync(new URL("tracker.js", root));
  new Function("window", "Matomo", src)(env.window, Matomo);
  if (!matomoReady) asyncInit.forEach((f) => f());
  return plugin;
}
