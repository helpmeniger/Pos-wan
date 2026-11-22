// Aperçu des leçons animées : node anim_preview.js <code> <sortie.png> t1,t2,... | --mp4 sortie.mp4 [fps]
// (rend la page assets/anim/player.html?l=<code>&t=0 puis appelle HM.render(t) pour chaque instant)
const { chromium } = require("playwright");
const path = require("path"), fs = require("fs"), { execFileSync } = require("child_process");
(async () => {
  const [code, out, arg, fpsArg] = process.argv.slice(2);
  const anim = path.resolve(__dirname, "../../../app_android/app/src/main/assets/anim");
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 960, height: 540 } });
  await p.goto(`file://${anim}/player.html?l=${code}&t=0`);
  await p.waitForFunction(() => document.body.dataset.ready === "1");
  if (out === "--mp4") {
    const fps = parseInt(fpsArg || "15");
    const dur = await p.evaluate(c => { const T = window.T[c]; return T.duration || HM.duration || (T.texts.length * 4); }, code);
    const dir = fs.mkdtempSync("/tmp/claude-0/-home-user-Pos-wan/92b7203b-bf0b-5727-ada7-f8588bc77b70/scratchpad/frames-");
    for (let i = 0; i < Math.ceil(dur * fps); i++) {
      await p.evaluate(t => HM.render(t), i / fps);
      await p.screenshot({ path: `${dir}/f${String(i).padStart(5, "0")}.png` });
    }
    const audio = `${anim}/audio/${code}.m4a`;
    const args = ["-v", "error", "-y", "-framerate", String(fps), "-i", `${dir}/f%05d.png`];
    if (fs.existsSync(audio)) args.push("-i", audio, "-c:a", "aac", "-shortest");
    args.push("-c:v", "libx264", "-pix_fmt", "yuv420p", "-crf", "24", arg);
    execFileSync("ffmpeg", args);
    fs.rmSync(dir, { recursive: true });
  } else {
    const times = arg.split(",").map(Number);
    const shots = [];
    for (const t of times) { await p.evaluate(t => HM.render(t), t); shots.push(await p.screenshot()); }
    const tmp = shots.map((s, i) => { const f = `${out}.${i}.png`; fs.writeFileSync(f, s); return f; });
    const cols = 3, rows = Math.ceil(tmp.length / cols);
    execFileSync("ffmpeg", ["-v", "error", "-y", ...tmp.flatMap(f => ["-i", f]), "-filter_complex",
      tmp.map((_, i) => `[${i}:v]drawtext=text='t=${times[i]}':x=8:y=8:fontsize=22:fontcolor=yellow:box=1:boxcolor=black@0.6[v${i}]`).join(";") + ";" +
      tmp.map((_, i) => `[v${i}]`).join("") + `xstack=inputs=${tmp.length}:layout=` +
      tmp.map((_, i) => `${(i % cols) * 960}_${Math.floor(i / cols) * 540}`).join("|") + (tmp.length < cols * rows ? `:fill=gray` : ""), out]);
    tmp.forEach(f => fs.unlinkSync(f));
  }
  await b.close();
})();
