#!/usr/bin/env node
/**
 * playwright-adapter.js
 * JSON-RPC-ish over stdin/stdout (one JSON object per line).
 *
 * Request:
 *   {"id":1,"method":"newContext","params":{"session":"agent-123","viewport":{"width":1280,"height":720}}}
 * Response:
 *   {"id":1,"ok":true,"result":{"contextId":"ctx_1"}}
 */

const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const readline = require("node:readline");
const { chromium } = require("playwright-core");
const childProcess = require("node:child_process");

function write(obj) {
  process.stdout.write(JSON.stringify(obj) + "\n");
}

function which(cmd) {
  try {
    const out = childProcess.execSync(`command -v ${cmd}`, { stdio: ["ignore", "pipe", "ignore"] });
    const p = out.toString("utf8").trim();
    return p || null;
  } catch {
    return null;
  }
}

function findChromeExecutable() {
  // Prefer env override
  if (process.env.FUEL_BROWSER_EXECUTABLE && fs.existsSync(process.env.FUEL_BROWSER_EXECUTABLE)) {
    return process.env.FUEL_BROWSER_EXECUTABLE;
  }

  const platform = process.platform;

  if (platform === "darwin") {
    const candidates = [
      "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
      "/Applications/Google Chrome Canary.app/Contents/MacOS/Google Chrome Canary",
      "/Applications/Chromium.app/Contents/MacOS/Chromium",
      "/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge",
      "/Applications/Brave Browser.app/Contents/MacOS/Brave Browser",
    ];
    for (const p of candidates) if (fs.existsSync(p)) return p;
  }

  // linux
  const cmds = ["google-chrome-stable", "google-chrome", "chromium-browser", "chromium", "microsoft-edge", "brave-browser", "brave"];
  for (const c of cmds) {
    const p = which(c);
    if (p) return p;
  }

  return null;
}

let browser = null;
let executablePath = null;

let ctxSeq = 0;
let pageSeq = 0;

const CONTEXT_TTL_MS = 30 * 60 * 1000; // 30 minutes

const contexts = new Map(); // contextId -> { context, session, lastActivity }
const pages = new Map();    // pageId -> { page, contextId }
const pageSnapshots = new Map(); // pageId -> { refMap: Map<string, node>, snapshot: object }

function touchContext(contextId) {
  const entry = contexts.get(contextId);
  if (entry) {
    entry.lastActivity = Date.now();
  }
}

async function closeStaleContexts() {
  const now = Date.now();
  for (const [contextId, entry] of contexts.entries()) {
    if (now - entry.lastActivity > CONTEXT_TTL_MS) {
      // Close pages in that context
      for (const [pid, p] of pages.entries()) {
        if (p.contextId === contextId) {
          try { await p.page.close({ runBeforeUnload: false }); } catch {}
          pages.delete(pid);
        }
      }
      try { await entry.context.close(); } catch {}
      contexts.delete(contextId);
    }
  }
}

// Check for stale contexts every minute
setInterval(closeStaleContexts, 60 * 1000);

// Exit if parent process dies (PPID becomes 1 = reparented to init)
const initialPpid = process.ppid;
setInterval(() => {
  if (process.ppid !== initialPpid || process.ppid === 1) {
    process.exit(0);
  }
}, 5000);

async function ensureBrowser() {
  if (browser) return;

  executablePath = findChromeExecutable();
  if (!executablePath) {
    const err = new Error("No system Chrome/Chromium found. Set FUEL_BROWSER_EXECUTABLE or install Chrome.");
    err.code = "NO_BROWSER";
    throw err;
  }

  browser = await chromium.launch({
    executablePath,
    headless: true,
    // You can add flags if needed:
    // args: ["--disable-dev-shm-usage"],
  });
}

function mkId(prefix, n) {
  return `${prefix}_${n}`;
}

async function handle(method, params) {
  switch (method) {
    case "ping":
      return { ok: true, result: { status: "ok" } };

    case "launch":
      await ensureBrowser();
      return { ok: true, result: { executablePath } };

    case "newContext": {
      await ensureBrowser();
      const contextId = params?.contextId;
      if (!contextId) {
        throw Object.assign(new Error("contextId is required"), { code: "MISSING_CONTEXT_ID" });
      }
      if (contexts.has(contextId)) {
        throw Object.assign(new Error(`contextId ${contextId} already exists`), { code: "DUPLICATE_CONTEXT_ID" });
      }

      const session = params?.session || "default";
      const viewport = params?.viewport || { width: 1280, height: 720 };
      const userAgent = params?.userAgent;
      const timezoneId = params?.timezoneId; // optional, e.g. "Europe/London"
      const colorScheme = params?.colorScheme; // optional: "dark" or "light"

      const context = await browser.newContext({
        viewport,
        userAgent,
        timezoneId,
        colorScheme,
        ignoreHTTPSErrors: true,
      });

      contexts.set(contextId, { context, session, lastActivity: Date.now() });
      return { ok: true, result: { contextId } };
    }

    case "newPage": {
      const contextId = params?.contextId;
      const pageId = params?.pageId;
      if (!pageId) {
        throw Object.assign(new Error("pageId is required"), { code: "MISSING_PAGE_ID" });
      }
      if (pages.has(pageId)) {
        throw Object.assign(new Error(`pageId ${pageId} already exists`), { code: "DUPLICATE_PAGE_ID" });
      }

      const entry = contexts.get(contextId);
      if (!entry) throw Object.assign(new Error(`Unknown contextId ${contextId}. Context may have expired after 30 minutes of inactivity. Create a new context with browser:create.`), { code: "NO_CONTEXT" });

      touchContext(contextId);
      const page = await entry.context.newPage();
      pages.set(pageId, { page, contextId });
      return { ok: true, result: { pageId } };
    }

    case "goto": {
      const pageId = params?.pageId;
      const url = params?.url;
      const waitUntil = params?.waitUntil || "load"; // "domcontentloaded" | "load" | "networkidle"
      const timeoutMs = params?.timeoutMs || 30000;
      const returnHtml = params?.html || false;

      const entry = pages.get(pageId);
      if (!entry) throw Object.assign(new Error(`Unknown pageId ${pageId}. Page may have expired after 30 minutes of inactivity. Create a new context with browser:create.`), { code: "NO_PAGE" });

      touchContext(entry.contextId);
      await entry.page.goto(url, { waitUntil, timeout: timeoutMs });

      // Clear any stored snapshot for this page since navigation happened
      pageSnapshots.delete(pageId);

      const result = { url: entry.page.url() };
      if (returnHtml) {
        result.html = await entry.page.content();
      }
      return { ok: true, result };
    }

    case "run": {
      const pageId = params?.pageId;
      const code = params?.code; // Playwright code with access to `page`
      const entry = pages.get(pageId);
      if (!entry) throw Object.assign(new Error(`Unknown pageId ${pageId}. Page may have expired after 30 minutes of inactivity. Create a new context with browser:create.`), { code: "NO_PAGE" });

      touchContext(entry.contextId);
      // Create async function with page in scope
      const AsyncFunction = Object.getPrototypeOf(async function(){}).constructor;
      const fn = new AsyncFunction('page', code);
      const value = await fn(entry.page);
      return { ok: true, result: { value } };
    }

    case "screenshot": {
      const pageId = params?.pageId;
      const outPath = params?.path || path.join(os.tmpdir(), `fuel_${pageId}.png`);
      const entry = pages.get(pageId);
      if (!entry) throw Object.assign(new Error(`Unknown pageId ${pageId}. Page may have expired after 30 minutes of inactivity. Create a new context with browser:create.`), { code: "NO_PAGE" });

      touchContext(entry.contextId);
      await entry.page.screenshot({ path: outPath, fullPage: !!params?.fullPage });
      return { ok: true, result: { path: outPath } };
    }

    case "snapshot": {
      const pageId = params?.pageId;
      const interactiveOnly = params?.interactiveOnly || false;
      const entry = pages.get(pageId);
      if (!entry) throw Object.assign(new Error(`Unknown pageId ${pageId}. Page may have expired after 30 minutes of inactivity. Create a new context with browser:create.`), { code: "NO_PAGE" });

      touchContext(entry.contextId);

      // Get accessibility tree
      const snapshot = await entry.page.accessibility.snapshot({
        interestingOnly: interactiveOnly
      });

      // Assign refs recursively and build flat ref map
      let refCounter = 0;
      const refMap = new Map();

      function assignRefs(node) {
        const ref = `@e${++refCounter}`;
        node.ref = ref;
        refMap.set(ref, node);
        if (node.children) {
          node.children.forEach(assignRefs);
        }
        return node;
      }

      const snapshotWithRefs = assignRefs(snapshot);

      // Store snapshot and ref map for this page
      pageSnapshots.set(pageId, {
        refMap,
        snapshot: snapshotWithRefs
      });

      return { ok: true, result: { snapshot: snapshotWithRefs } };
    }

    case "click": {
      const pageId = params?.pageId;
      const selector = params?.selector;
      const ref = params?.ref;

      const entry = pages.get(pageId);
      if (!entry) throw Object.assign(new Error(`Unknown pageId ${pageId}. Page may have expired after 30 minutes of inactivity. Create a new context with browser:create.`), { code: "NO_PAGE" });

      touchContext(entry.contextId);

      let targetSelector = selector;

      // If ref is provided, resolve it from stored snapshot
      if (ref) {
        const snapshotData = pageSnapshots.get(pageId);
        if (!snapshotData) {
          throw Object.assign(new Error(`No snapshot found for page ${pageId}. Run browser:snapshot first.`), { code: "NO_SNAPSHOT" });
        }

        const node = snapshotData.refMap.get(ref);
        if (!node) {
          throw Object.assign(new Error(`Unknown ref ${ref}. Available refs from last snapshot: ${Array.from(snapshotData.refMap.keys()).join(', ')}`), { code: "BAD_REF" });
        }

        // Use role and name to locate the element
        if (node.role && node.name) {
          await entry.page.getByRole(node.role, { name: node.name, exact: true }).click();
        } else {
          throw Object.assign(new Error(`Cannot click ref ${ref}: node lacks role/name for location`), { code: "UNCLICKABLE_REF" });
        }
      } else if (targetSelector) {
        // Click by CSS selector
        await entry.page.click(targetSelector);
      } else {
        throw Object.assign(new Error(`Must provide either selector or ref`), { code: "BAD_PARAMS" });
      }

      return { ok: true, result: { clicked: true } };
    }

    case "fill": {
      const pageId = params?.pageId;
      const selector = params?.selector;
      const value = params?.value;
      const ref = params?.ref;

      const entry = pages.get(pageId);
      if (!entry) throw Object.assign(new Error(`Unknown pageId ${pageId}. Page may have expired after 30 minutes of inactivity. Create a new context with browser:create.`), { code: "NO_PAGE" });

      touchContext(entry.contextId);

      if (value === undefined) {
        throw Object.assign(new Error(`Must provide value parameter`), { code: "BAD_PARAMS" });
      }

      // If ref is provided, resolve it from stored snapshot
      if (ref) {
        const snapshotData = pageSnapshots.get(pageId);
        if (!snapshotData) {
          throw Object.assign(new Error(`No snapshot found for page ${pageId}. Run browser:snapshot first.`), { code: "NO_SNAPSHOT" });
        }

        const node = snapshotData.refMap.get(ref);
        if (!node) {
          throw Object.assign(new Error(`Unknown ref ${ref}. Available refs from last snapshot: ${Array.from(snapshotData.refMap.keys()).join(', ')}`), { code: "BAD_REF" });
        }

        // Use role and name to locate the element
        if (node.role && node.name) {
          await entry.page.getByRole(node.role, { name: node.name, exact: true }).fill(value);
        } else {
          throw Object.assign(new Error(`Cannot fill ref ${ref}: node lacks role/name for location`), { code: "UNFILLABLE_REF" });
        }
      } else if (selector) {
        // Fill by CSS selector
        await entry.page.fill(selector, value);
      } else {
        throw Object.assign(new Error(`Must provide either selector or ref`), { code: "BAD_PARAMS" });
      }

      return { ok: true, result: { filled: true } };
    }

    case "type": {
      const pageId = params?.pageId;
      const selector = params?.selector;
      const text = params?.text;
      const ref = params?.ref;
      const delay = params?.delay || 0;

      const entry = pages.get(pageId);
      if (!entry) throw Object.assign(new Error(`Unknown pageId ${pageId}. Page may have expired after 30 minutes of inactivity. Create a new context with browser:create.`), { code: "NO_PAGE" });

      touchContext(entry.contextId);

      if (text === undefined) {
        throw Object.assign(new Error(`Must provide text parameter`), { code: "BAD_PARAMS" });
      }

      // If ref is provided, resolve it from stored snapshot
      if (ref) {
        const snapshotData = pageSnapshots.get(pageId);
        if (!snapshotData) {
          throw Object.assign(new Error(`No snapshot found for page ${pageId}. Run browser:snapshot first.`), { code: "NO_SNAPSHOT" });
        }

        const node = snapshotData.refMap.get(ref);
        if (!node) {
          throw Object.assign(new Error(`Unknown ref ${ref}. Available refs from last snapshot: ${Array.from(snapshotData.refMap.keys()).join(', ')}`), { code: "BAD_REF" });
        }

        // Use role and name to locate the element
        if (node.role && node.name) {
          await entry.page.getByRole(node.role, { name: node.name, exact: true }).type(text, { delay });
        } else {
          throw Object.assign(new Error(`Cannot type in ref ${ref}: node lacks role/name for location`), { code: "UNTYPEABLE_REF" });
        }
      } else if (selector) {
        // Type by CSS selector
        await entry.page.type(selector, text, { delay });
      } else {
        throw Object.assign(new Error(`Must provide either selector or ref`), { code: "BAD_PARAMS" });
      }

      return { ok: true, result: { typed: true } };
    }

    case "closeContext": {
      const contextId = params?.contextId;
      const entry = contexts.get(contextId);
      if (!entry) return { ok: true, result: { closed: false } };

      // Close pages in that context
      for (const [pid, p] of pages.entries()) {
        if (p.contextId === contextId) {
          try { await p.page.close({ runBeforeUnload: false }); } catch {}
          pages.delete(pid);
        }
      }
      await entry.context.close();
      contexts.delete(contextId);
      return { ok: true, result: { closed: true } };
    }

    case "status": {
      return {
        ok: true,
        result: {
          browserLaunched: browser !== null,
          contexts: Array.from(contexts.keys()),
          pages: Array.from(pages.keys()),
        },
      };
    }

    default:
      throw Object.assign(new Error(`Unknown method: ${method}`), { code: "NO_METHOD" });
  }
}

const rl = readline.createInterface({ input: process.stdin, crlfDelay: Infinity });
rl.on("line", async (line) => {
  if (!line.trim()) return;

  let msg;
  try {
    msg = JSON.parse(line);
  } catch (e) {
    write({ id: null, ok: false, error: { code: "BAD_JSON", message: e.message } });
    return;
  }

  const id = msg.id ?? null;
  try {
    const out = await handle(msg.method, msg.params);
    write({ id, ...out });
  } catch (e) {
    write({
      id,
      ok: false,
      error: { code: e.code || "ERR", message: e.message || String(e) },
    });
  }
});

process.on("SIGTERM", async () => {
  try { if (browser) await browser.close(); } catch {}
  process.exit(0);
});
