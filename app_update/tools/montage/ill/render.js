// Rend les illustrations : node render.js [id ...]  →  res/drawable-nodpi/ill_<id>.webp (1280 x 720)
const { chromium } = require("playwright");
const path = require("path"), fs = require("fs"), { execFileSync } = require("child_process");
const OUT = path.resolve(__dirname, "../../../../app_android/app/src/main/res/drawable-nodpi");
const TMP = process.env.ILL_TMP || "/tmp/ill"; fs.mkdirSync(TMP, { recursive: true });
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1280, height: 720 } });
  const errs = [];
  p.on("console", m => { if (m.type() === "error") errs.push(m.text()); });
  p.on("pageerror", e => errs.push(e.message));
  await p.goto(`file://${__dirname}/ill.html?id=windows-06&t=1`);
  await p.waitForTimeout(300);
  const ids = process.argv.slice(2).length ? process.argv.slice(2) : await p.evaluate(() => Object.keys(window.SPECS));
  for (const id of ids) {
    errs.length = 0;
    await p.goto(`file://${__dirname}/ill.html?id=${id}&t=1`);
    await p.waitForFunction(() => document.body.dataset.ready === "1", null, { timeout: 8000 }).catch(() => errs.push("pas prêt"));
    await p.waitForTimeout(80);
    const png = `${TMP}/${id}.png`;
    await p.screenshot({ path: png });
    execFileSync("ffmpeg", ["-v", "error", "-y", "-i", png, "-c:v", "libwebp", "-quality", "80", `${OUT}/ill_${id.replace(/-/g, "_")}.webp`]);
    console.log(id, errs.length ? "⚠ " + errs.join(" | ") : "ok");
  }
  await b.close();
})();
