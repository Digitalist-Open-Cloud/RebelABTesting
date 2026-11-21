import { assertEquals } from "jsr:@std/assert@1";
import { loadTracker, makeEnv, makeStorage, today } from "./harness.js";

const A = "0123456789abcdef0123456789abcdef";
const B = "b".repeat(32);
const record = (v, a, days) => JSON.stringify({ v, a, exp: today(days) });

Deno.test("every experiment the visitor takes part in is sent", () => {
  const storage = makeStorage({ "sabt:3": record(1, B, 0), "sabt:7": record(3, A, 5), "other": "x" });
  assertEquals(loadTracker(makeEnv({ localStorage: storage })).log(), `&rabt=3-1-${B},7-3-${A}`);
});

Deno.test("experiments that ended, that the visitor is not part of, or that are not well-formed are left out", () => {
  const storage = makeStorage({
    "sabt:1": record(1, A, -1),
    "sabt:2": record(0, A, 5),
    "sabt:3": record("2", A, 5),
    "sabt:4": record(2, "not-hex", 5),
    "sabt:5": "not json",
  });
  assertEquals(loadTracker(makeEnv({ localStorage: storage })).log(), "");
});

Deno.test("nothing is sent when localStorage is blocked", () => {
  const env = makeEnv({ localStorage: makeStorage({}, { throwOnGet: true }) });
  assertEquals(loadTracker(env).log(), "");
});

Deno.test("the plugin registers when Matomo loads after it", () => {
  const env = makeEnv({ localStorage: makeStorage({ "sabt:7": record(1, A, 2) }) });
  assertEquals(loadTracker(env, { matomoReady: false }).log(), `&rabt=7-1-${A}`);
});

Deno.test("all kinds of requests carry the experiments", () => {
  const plugin = loadTracker(makeEnv({ localStorage: makeStorage({ "sabt:7": record(2, A, 2) }) }));
  for (const hook of ["log", "link", "goal", "event", "sitesearch", "ecommerce", "ping", "contentImpression", "contentInteraction"]) {
    assertEquals(plugin[hook](), `&rabt=7-2-${A}`);
  }
});
