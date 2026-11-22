import { assertEquals } from "jsr:@std/assert@1";

const root = new URL("../../", import.meta.url);
const window = {};
new Function("window", Deno.readTextFileSync(new URL("javascripts/experimentForm.js", root)))(window);
const { requiredVisits, requiredDays } = window.RebelABTesting;

Deno.test("a rate of 5% and a variant that is 10% better need about 25,000 visits in each group", () => {
  // (1.6449 + 0.8416)^2 * (0.05 * 0.95 + 0.055 * 0.945) / 0.005^2 = 6.18268 * 3979 = 24600.9
  assertEquals(requiredVisits(0.05, 0.10), 24601);
});

Deno.test("a difference twice as large needs about a quarter of the visits", () => {
  // 6.18268 * (0.05 * 0.95 + 0.06 * 0.94) / 0.01^2 = 6.18268 * 1039 = 6423.8
  assertEquals(requiredVisits(0.05, 0.20), 6424);
});

Deno.test("being surer needs more visits, and being less sure fewer", () => {
  // (2.3263 + 0.8416)^2 = 10.0356 and (1.2816 + 0.8416)^2 = 4.5080, times 3979
  assertEquals(requiredVisits(0.05, 0.10, 99), 39932);
  assertEquals(requiredVisits(0.05, 0.10, 90), 17938);
  assertEquals(requiredVisits(0.05, 0.10, 95), requiredVisits(0.05, 0.10));
});

Deno.test("nothing is said for numbers that cannot be", () => {
  for (const [rate, effect] of [[0, 0.1], [1, 0.1], [0.6, 0.8], [0.05, 0], [NaN, 0.1], [0.05, NaN]]) {
    assertEquals(requiredVisits(rate, effect), null);
  }
});

Deno.test("the days follow from the groups, the visits a day and the share of visitors", () => {
  assertEquals(requiredDays(1000, 2, 500, 1), 4);
  assertEquals(requiredDays(1000, 3, 500, 1), 6);
  assertEquals(requiredDays(1000, 2, 500, 0.5), 8);
  assertEquals(requiredDays(1001, 2, 500, 1), 5);
  assertEquals(requiredDays(null, 2, 500, 1), null);
  assertEquals(requiredDays(1000, 2, NaN, 1), null);
});
