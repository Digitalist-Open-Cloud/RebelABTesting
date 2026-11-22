import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { loadTag, makeEnv, makeStorage, today } from "./harness.js";

const A = "0123456789abcdef0123456789abcdef";
const experiment = (over = {}) => ({
  id: 7, from_date: today(-5), to_date: today(5), share: 100, match: "any", value: "",
  variants: [{ css: "h1{color:red}", js: "window.x=1" }], ...over,
});
const stored = (storage, id) => JSON.parse(storage.getItem("sabt:" + id));

// Math.random() answers with these values in turn: first for taking part, then for the variant.
async function fireWith(randoms, env, params) {
  const original = Math.random;
  Math.random = () => randoms.shift() ?? 0;
  try { await loadTag(env, params).fire(); } finally { Math.random = original; }
}

Deno.test("a new visitor gets a variant, which is applied, kept and sent", async () => {
  const env = makeEnv({ experiments: [experiment()] });
  await fireWith([0, 0.9], env);

  const record = stored(env.localStorage, 7);
  assertEquals(record.v, 2);
  assertEquals(record.exp, today(5));
  assertMatch(record.a, /^[0-9a-f]{32}$/);
  assertEquals(env.appended.map((n) => [n.tagName, n.textContent]), [["style", "h1{color:red}"], ["script", "window.x=1"]]);
  assertEquals(env._paq, [["appendToTrackingUrl", `rabt=7-2-${record.a}`], ["ping"]]);
  assertEquals(env.fetchCalls, ["https://m.example/index.php?module=RebelABTesting&action=getExperimentsPublic&idSite=1"]);
});

Deno.test("the original changes nothing on the page and is counted", async () => {
  const env = makeEnv({ experiments: [experiment()] });
  await fireWith([0, 0.1], env);

  assertEquals(stored(env.localStorage, 7).v, 1);
  assertEquals(env.appended, []);
  assertEquals(env._paq[0][1].slice(0, 9), "rabt=7-1-");
});

Deno.test("each of several variants can be drawn", async () => {
  const variants = [{ css: "a{}", js: "" }, { css: "b{}", js: "" }];
  for (const [random, v, css] of [[0.1, 1, []], [0.5, 2, ["a{}"]], [0.9, 3, ["b{}"]]]) {
    const env = makeEnv({ experiments: [experiment({ variants })] });
    await fireWith([0, random], env);
    assertEquals(stored(env.localStorage, 7).v, v);
    assertEquals(env.appended.map((n) => n.textContent), css);
  }
});

Deno.test("a visitor outside the share of the experiment gets the original and is not counted", async () => {
  const env = makeEnv({ experiments: [experiment({ share: 10 })] });
  await fireWith([0.5, 0.9], env);

  assertEquals(stored(env.localStorage, 7).v, 0);
  assertEquals(env.appended, []);
  assertEquals(env._paq, []);
});

Deno.test("a returning visitor keeps the variant, also when the experiment was prolonged", async () => {
  const storage = makeStorage({ "sabt:7": JSON.stringify({ v: 2, a: A, exp: today(1) }) });
  const env = makeEnv({ experiments: [experiment({ to_date: today(10) })], localStorage: storage });
  await fireWith([0, 0.1], env);

  assertEquals(stored(storage, 7), { v: 2, a: A, exp: today(10) });
  assertEquals(env._paq[0], ["appendToTrackingUrl", `rabt=7-2-${A}`]);
  assertEquals(env.appended.length, 2);
});

Deno.test("experiments run side by side, each on its own pages", async () => {
  const experiments = [
    experiment({ id: 1, match: "contains", value: "/pricing", variants: [{ css: "one{}", js: "" }] }),
    experiment({ id: 2, match: "starts_with", value: "https://shop.example/cart", variants: [{ css: "two{}", js: "" }] }),
    experiment({ id: 3, match: "regexp", value: "/pric(e|ing)$", variants: [{ css: "three{}", js: "" }] }),
    experiment({ id: 4, match: "equals", value: "https://shop.example/", variants: [{ css: "four{}", js: "" }] }),
    experiment({ id: 5, match: "regexp", value: "(", variants: [{ css: "five{}", js: "" }] }),
  ];
  const env = makeEnv({ experiments });
  await fireWith([0, 0.9, 0, 0.9], env);

  assertEquals(env.appended.map((n) => n.textContent), ["one{}", "three{}"]);
  assertMatch(env._paq[0][1], /^rabt=1-2-[0-9a-f]{32},3-2-[0-9a-f]{32}$/);
  assertEquals(stored(env.localStorage, 2), null);
});

Deno.test("an experiment of another page is still sent with the requests of this page", async () => {
  const storage = makeStorage({ "sabt:9": JSON.stringify({ v: 2, a: A, exp: today(3) }) });
  const env = makeEnv({ experiments: [experiment({ id: 9, match: "contains", value: "/checkout" })], localStorage: storage });
  await fireWith([], env);

  assertEquals(env.appended, []);
  assertEquals(env._paq[0], ["appendToTrackingUrl", `rabt=9-2-${A}`]);
});

Deno.test("an experiment outside its days is not run", async () => {
  const env = makeEnv({ experiments: [experiment({ from_date: today(2), to_date: today(9) })] });
  await fireWith([0, 0.9], env);

  assertEquals(env._paq, []);
  assertEquals(env.localStorage.getItem("sabt:7"), null);
});

Deno.test("assignments of experiments that have ended are removed", async () => {
  const storage = makeStorage({
    "sabt:3": JSON.stringify({ v: 1, a: A, exp: today(-2) }),
    "sabt:4": "not json",
    "other": "x",
  });
  await fireWith([0, 0.9], makeEnv({ experiments: [experiment()], localStorage: storage }));

  assertEquals(storage.getItem("sabt:3"), null);
  assertEquals(storage.getItem("sabt:4"), null);
  assertEquals(storage.getItem("other"), "x");
});

Deno.test("the page is hidden while the experiments are fetched, and shown afterwards", async () => {
  const env = makeEnv({ experiments: [experiment()] });
  const hidden = [];
  const fetch = env.fetch;
  env.fetch = (url) => { hidden.push(env.appended.map((n) => n.textContent)); return fetch(url); };
  await fireWith([0, 0.1], env);

  assertEquals(hidden, [["html{opacity:0 !important}"]]);
  assertEquals(env.appended, []);
});

Deno.test("the page is shown again when Matomo does not answer", async () => {
  const env = makeEnv({ fetchFails: true });
  await fireWith([], env);

  assertEquals(env.appended, []);
  assertEquals(env._paq, []);
});

Deno.test("the page is never hidden with a timeout of 0", async () => {
  const env = makeEnv({ experiments: [experiment()] });
  let hidden = null;
  const fetch = env.fetch;
  env.fetch = (url) => { hidden = env.appended.length; return fetch(url); };
  await fireWith([0, 0.1], env, { hideTimeout: 0 });

  assertEquals(hidden, 0);
});

Deno.test("on the next page the experiments are run at once, without fetching or hiding", async () => {
  const env = makeEnv({ experiments: [experiment()] });
  await fireWith([0, 0.9], env);
  env.appended.length = 0;
  env._paq.length = 0;
  env.fetchCalls.length = 0;

  const tag = loadTag(env);
  tag.fire();
  assertEquals(env.appended.map((n) => n.tagName), ["style", "script"]);
  assertEquals(env._paq.length, 1);
  assertEquals(env.fetchCalls, []);
  await new Promise((resolve) => setTimeout(resolve, 0));
});

Deno.test("experiments kept for more than five minutes are fetched again for the next page", async () => {
  const cache = JSON.stringify({ t: Date.now() - 301000, e: [] });
  const env = makeEnv({ experiments: [experiment()], localStorage: makeStorage({ "sabt-experiments:1": cache }) });
  await fireWith([0, 0.9], env);

  assertEquals(env.appended, []);
  assertEquals(env.fetchCalls.length, 1);
  assertEquals(JSON.parse(env.localStorage.getItem("sabt-experiments:1")).e.length, 1);
});

Deno.test("nothing happens without localStorage", async () => {
  for (const opts of [{ throwOnGet: true }, { throwOnSet: true }]) {
    const env = makeEnv({ experiments: [experiment()], localStorage: makeStorage({}, opts) });
    await fireWith([0, 0.9], env);
    assertEquals(env._paq, []);
    assert(env.appended.length === 0);
  }
});

Deno.test("nothing happens without the website of the container", async () => {
  const env = makeEnv({ experiments: [experiment()] });
  await fireWith([0, 0.9], env, { container: {} });

  assertEquals(env.fetchCalls, []);
});

Deno.test("a preview link shows its variant without keeping or sending anything", async () => {
  const variants = [{ css: "a{}", js: "" }, { css: "b{}", js: "" }];
  const env = makeEnv({ experiments: [experiment({ variants, match: "contains", value: "/elsewhere" })], hash: `#rabt-preview=7-3-${A}` });
  await fireWith([0, 0.1], env);

  assertEquals(env.appended.map((n) => n.textContent), ["b{}"]);
  assertEquals(env.fetchCalls, [`https://m.example/index.php?module=RebelABTesting&action=getExperimentsPublic&idSite=1&preview=7-${A}`]);
  assertEquals(env._paq, []);
  assertEquals(env.localStorage.length, 0);
});

Deno.test("a preview lasts for the tab and is ended with off", async () => {
  const env = makeEnv({ experiments: [experiment()], hash: `#rabt-preview=7-2-${A}` });
  await fireWith([], env);
  env.appended.length = 0;

  env.window.location.hash = "";
  await fireWith([], env);
  assertEquals(env.appended.length, 2);
  assertEquals(env._paq, []);

  env.appended.length = 0;
  env.window.location.hash = "#rabt-preview=off";
  await fireWith([0, 0.1], env);
  assertEquals(env.appended, []);
  assertEquals(env._paq.length, 2);
});

Deno.test("the preview of the original changes nothing on the page", async () => {
  const env = makeEnv({ experiments: [experiment()], hash: `#rabt-preview=7-1-${A}` });
  await fireWith([0, 0.9], env);

  assertEquals(env.appended, []);
  assertEquals(env._paq, []);
});

const door = (over = {}) => experiment({
  kind: "fake_door", variants: [],
  door: { selector: "#menu", position: "append", element: "link", label: "Compare plans", css_class: "nav-link", message: "Coming soon" },
  ...over,
});

Deno.test("a fake door is added to the page and told to Matomo once per visit", async () => {
  const env = makeEnv({ experiments: [door()], elements: ["#menu"] });
  await fireWith([0], env);

  const [selector, position, element] = env.placed[0];
  assertEquals([selector, position, element.tagName, element.textContent, element.className, element.href], ["#menu", "beforeend", "a", "Compare plans", "nav-link", "#"]);
  const record = stored(env.localStorage, 7);
  assertEquals(record.v, 2);
  assertEquals(env._paq, [
    ["appendToTrackingUrl", `rabt=7-2-${record.a}&rabtd=7-s`],
    ["ping"],
    ["appendToTrackingUrl", `rabt=7-2-${record.a}`],
    ["appendToTrackingUrl", `rabt=7-2-${record.a}`],
    ["ping"],
  ]);

  env._paq.length = 0;
  await loadTag(env).fire();
  assertEquals(env.placed.length, 2);
  assertEquals(env._paq, [["appendToTrackingUrl", `rabt=7-2-${record.a}`]]);
});

Deno.test("a click on a fake door shows the message and is told to Matomo", async () => {
  const env = makeEnv({ experiments: [door({ door: { selector: "#menu", position: "after", element: "button", label: "Export", css_class: "", message: "Not there yet" } })], elements: ["#menu"] });
  await fireWith([0], env);
  const element = env.placed[0][2];
  const a = stored(env.localStorage, 7).a;
  env._paq.length = 0;

  element.click();

  assertEquals([env.placed[0][1], element.tagName, element.type], ["afterend", "button", "button"]);
  assertEquals([element.replacedWith.textContent, element.replacedWith.attributes.role], ["Not there yet", "status"]);
  assertEquals(env._paq, [
    ["appendToTrackingUrl", `rabt=7-2-${a}&rabtd=7-c`],
    ["trackEvent", "Fake door", "Click", "7"],
    ["appendToTrackingUrl", `rabt=7-2-${a}`],
  ]);
});

Deno.test("a fake door is not shown or told on a page without its element", async () => {
  for (const selector of ["#elsewhere", "("]) {
    const env = makeEnv({ experiments: [door({ door: { ...door().door, selector } })], elements: ["#menu"] });
    await fireWith([0], env);

    assertEquals(env.placed, []);
    assert(!env._paq.some((call) => String(call[1]).includes("rabtd")));
  }
});

Deno.test("a visitor outside the share of a fake door does not get it", async () => {
  const env = makeEnv({ experiments: [door({ share: 10 })], elements: ["#menu"] });
  await fireWith([0.5], env);

  assertEquals(env.placed, []);
  assertEquals(env._paq, []);
});

Deno.test("the preview of a fake door shows it without telling Matomo", async () => {
  const env = makeEnv({ experiments: [door()], elements: ["#menu"], hash: `#rabt-preview=7-2-${A}` });
  await fireWith([0], env);
  env.placed[0][2].click();

  assertEquals(env.placed.length, 1);
  assertEquals(env._paq, []);
});

// An element of the page that records what a change does to it.
function element(over = {}) {
  const node = {
    textContent: "old", className: "btn", attributes: { src: "old.png", srcset: "old-2x.png 2x", href: "/old" },
    style: { cssText: "color: black", properties: {}, setProperty(name, value, priority) { this.properties[name] = [value, priority]; } },
    setAttribute(name, value) { node.attributes[name] = value; },
    removeAttribute(name) { delete node.attributes[name]; },
    ...over,
  };
  return node;
}

Deno.test("the changes of a variant are made to the elements they point at", async () => {
  const page = { "h1": [element(), element()], ".banner": [element()], ".buy": [element()], "img.hero": [element()], "a.more": [element()] };
  const changes = [
    { selector: "h1", action: "text", value: "New <headline>" },
    { selector: ".banner", action: "hide", value: "" },
    { selector: ".buy", action: "add_class", value: "big green" },
    { selector: ".buy", action: "style", value: "background: green" },
    { selector: "img.hero", action: "image", value: "/new.png" },
    { selector: "a.more", action: "link", value: "https://shop.example/more" },
    { selector: ".not-there", action: "hide", value: "" },
    { selector: "(", action: "hide", value: "" },
  ];
  const env = makeEnv({ experiments: [experiment({ variants: [{ changes, css: "", js: "" }] })], page });
  await fireWith([0, 0.9], env);

  assertEquals(page.h1.map((e) => e.textContent), ["New <headline>", "New <headline>"]);
  assertEquals(page[".banner"][0].style.properties, { display: ["none", "important"] });
  assertEquals(page[".buy"][0].className, "btn big green");
  assertEquals(page[".buy"][0].style.cssText, "color: black;background: green");
  assertEquals(page["img.hero"][0].attributes, { src: "/new.png", href: "/old" });
  assertEquals(page["a.more"][0].attributes.href, "https://shop.example/more");
  assertEquals(env.appended, []);
});

Deno.test("the original leaves the elements as they are", async () => {
  const page = { "h1": [element()] };
  const changes = [{ selector: "h1", action: "text", value: "New" }];
  const env = makeEnv({ experiments: [experiment({ variants: [{ changes, css: "", js: "" }] })], page });
  await fireWith([0, 0.1], env);

  assertEquals(page.h1[0].textContent, "old");
});

Deno.test("elements that arrive while the page loads are changed too, each once", async () => {
  const page = { ".buy": [element()] };
  const changes = [{ selector: ".buy", action: "add_class", value: "green" }];
  const env = makeEnv({ experiments: [experiment({ variants: [{ changes, css: "", js: "" }] })], page });
  let loaded;
  env.document.readyState = "loading";
  env.document.addEventListener = (_type, listener) => { loaded = listener; };
  await fireWith([0, 0.9], env);

  page[".buy"].push(element());
  loaded();
  loaded();

  assertEquals(page[".buy"].map((e) => e.className), ["btn green", "btn green"]);
});

const watched = (over = {}) => experiment({ kind: "watch", variants: [], door: { selector: ".signup" }, ...over });

// A browser that tells what is in view when the test says so.
function withIntersectionObserver(test) {
  const observers = [];
  globalThis.IntersectionObserver = class {
    constructor(callback) { this.callback = callback; this.observed = []; observers.push(this); }
    observe(node) { this.observed.push(node); }
    disconnect() { this.disconnected = true; }
  };
  return test(observers).finally(() => { delete globalThis.IntersectionObserver; });
}

Deno.test("a watched element is told to Matomo when it comes into view and when it is clicked", () => withIntersectionObserver(async (observers) => {
  const clicks = [];
  const elements = [0, 1].map(() => ({ addEventListener(_type, listener) { clicks.push(listener); } }));
  const env = makeEnv({ experiments: [watched()], page: { ".signup": elements } });
  await fireWith([0], env);
  const a = stored(env.localStorage, 7).a;

  assertEquals(observers[0].observed, elements);
  assert(!env._paq.some((call) => String(call[1]).includes("rabtd")));
  assertEquals(env.appended, []);

  env._paq.length = 0;
  observers[0].callback([{ isIntersecting: false }]);
  assertEquals(env._paq, []);
  observers[0].callback([{ isIntersecting: false }, { isIntersecting: true }]);
  assertEquals(env._paq, [["appendToTrackingUrl", `rabt=7-2-${a}&rabtd=7-s`], ["ping"], ["appendToTrackingUrl", `rabt=7-2-${a}`]]);
  assert(observers[0].disconnected);

  env._paq.length = 0;
  clicks[1]();
  clicks[0]();
  assertEquals(env._paq, [
    ["appendToTrackingUrl", `rabt=7-2-${a}&rabtd=7-c`],
    ["trackEvent", "Watched element", "Click", "7"],
    ["appendToTrackingUrl", `rabt=7-2-${a}`],
  ]);
}));

Deno.test("a browser that cannot tell what is in view counts the page with the watched element", async () => {
  const env = makeEnv({ experiments: [watched()], page: { ".signup": [{ addEventListener() {} }] } });
  await fireWith([0], env);

  assert(env._paq.some((call) => String(call[1]).endsWith("&rabtd=7-s")));
});

Deno.test("nothing is told on a page without the watched element, or to a visitor outside the share", async () => {
  for (const [experiments, randoms] of [[[watched()], [0]], [[watched({ door: { selector: "(" } })], [0]], [[watched({ share: 10 })], [0.5]]]) {
    const env = makeEnv({ experiments, page: { ".other": [{}] } });
    await fireWith(randoms, env);

    assert(!env._paq.some((call) => String(call[1]).includes("rabtd")));
  }
});

Deno.test("the visit is told to the queue Matomo uses now, not to the one from before it loaded", async () => {
  const env = makeEnv({ experiments: [experiment()] });
  const before = env.window._paq;
  const fetch = env.fetch;
  // Matomo finishes loading while the experiments are fetched, and puts its own queue in place.
  env.fetch = (url) => { env.window._paq = []; return fetch(url); };
  await fireWith([0, 0.9], env);

  assertEquals(before, []);
  assertEquals(env.window._paq.map((call) => call[0]), ["appendToTrackingUrl", "ping"]);
});

Deno.test("a text is inserted before, after or at the top of an element, and can be styled by a later change", async () => {
  const placed = [];
  const target = element({ insertAdjacentElement(position, node) { placed.push([position, node.className, node.textContent]); page[".rebel-experiment-insert"].push(element({ ...node, style: element().style })); } });
  const page = { ".buy": [target], ".rebel-experiment-insert": [] };
  const changes = [
    { selector: ".buy", action: "insert_before", value: "Before" },
    { selector: ".buy", action: "insert_after", value: "Free <returns>" },
    { selector: ".buy", action: "insert_first", value: "First" },
    { selector: ".rebel-experiment-insert", action: "style", value: "color: green" },
  ];
  const env = makeEnv({ experiments: [experiment({ variants: [{ changes, css: "", js: "" }] })], page });
  await fireWith([0, 0.9], env);

  assertEquals(placed, [
    ["beforebegin", "rebel-experiment-insert", "Before"],
    ["afterend", "rebel-experiment-insert", "Free <returns>"],
    ["afterbegin", "rebel-experiment-insert", "First"],
  ]);
  assertEquals(page[".rebel-experiment-insert"].map((e) => e.style.cssText), Array(3).fill("color: black;color: green"));
});

Deno.test("a variant sends the visitor to its page, with what the address carries", async () => {
  const variants = [{ changes: [{ selector: "h1", action: "text", value: "not on this page" }], redirect: "/pricing-b?plan=2", css: "", js: "" }];
  const env = makeEnv({ experiments: [experiment({ variants })], url: "https://shop.example/pricing?utm_campaign=spring&plan=1#top", page: { "h1": [element()] } });
  await fireWith([0, 0.9], env);

  assertEquals(env.sentTo, "https://shop.example/pricing-b?plan=2&utm_campaign=spring#top");
  assertEquals(stored(env.localStorage, 7).v, 2);
  // Matomo is told on the page the visitor arrives at. A request from this page would be cut off.
  assertEquals(env._paq, []);
  assertEquals(env.localStorage.getItem("sabt-told"), null);
});

Deno.test("the original and a visitor who is already on the page of the variant stay where they are", async () => {
  const variants = [{ changes: [], redirect: "https://shop.example/pricing-b", css: "", js: "" }];

  const original = makeEnv({ experiments: [experiment({ variants })] });
  await fireWith([0, 0.1], original);
  assertEquals(original.sentTo, undefined);

  const arrived = makeEnv({ experiments: [experiment({ variants })], url: "https://shop.example/pricing-b?utm_campaign=spring" });
  await fireWith([0, 0.9], arrived);
  assertEquals(arrived.sentTo, undefined);
  assertEquals(arrived._paq.map((call) => call[0]), ["appendToTrackingUrl", "ping"]);
});

Deno.test("each visit is told to Matomo once, also when the experiments are kept from an earlier page", async () => {
  const cache = JSON.stringify({ t: Date.now(), e: [experiment()] });
  const assignment = JSON.stringify({ v: 2, a: A, exp: today(5) });
  const env = makeEnv({ localStorage: makeStorage({ "sabt-experiments:1": cache, "sabt:7": assignment }) });

  await fireWith([], env);
  assertEquals(env._paq, [["appendToTrackingUrl", `rabt=7-2-${A}`], ["ping"]]);

  env._paq.length = 0;
  await fireWith([], env);
  assertEquals(env._paq, [["appendToTrackingUrl", `rabt=7-2-${A}`]]);

  env._paq.length = 0;
  env.localStorage.setItem("sabt-told", String(Date.now() - 31 * 60 * 1000));
  await fireWith([], env);
  assertEquals(env._paq, [["appendToTrackingUrl", `rabt=7-2-${A}`], ["ping"]]);
});
