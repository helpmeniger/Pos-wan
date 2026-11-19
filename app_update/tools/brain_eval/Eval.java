import com.hightechniger.help_me.brain.Brain;
import java.nio.file.*;
import java.text.Normalizer;
import java.util.*;
import java.util.regex.Pattern;

/**
 * Évalue l'assistant sur cases.txt et compare avec l'ancien moteur (OldEngine).
 * Usage : CLS=Eval ./probe.sh [-v]
 */
public class Eval {
    static String fold(String s) {
        return Normalizer.normalize(s.toLowerCase().replace('’', '\''), Normalizer.Form.NFD).replaceAll("\\p{M}", "");
    }

    public static void main(String[] args) throws Exception {
        boolean verbose = args.length > 0 && args[0].equals("-v");
        for (String a : args) if (a.contains("=")) Brain.tune(a.split("=")[0], Double.parseDouble(a.split("=")[1]));
        Path assets = Paths.get(System.getProperty("brain")).getParent();
        Brain brain = Brain.fromJson(new String(Files.readAllBytes(assets.resolve("brain.json")), "UTF-8"), null);
        OldEngine old = new OldEngine(assets, assets.resolve("../../../../../app_update/knowledge").normalize());
        List<String> lines = Files.readAllLines(Paths.get(System.getProperty("cases")));
        Map<String, int[]> cat = new LinkedHashMap<>();   // [total, new ok, old ok]
        String section = "?";
        int n = 0, okN = 0, okO = 0;
        List<String> fails = new ArrayList<>();
        long t0 = System.nanoTime();
        for (String line : lines) {
            if (line.startsWith("## ")) { section = line.replaceAll("^## -+ ?", ""); continue; }
            if (line.isBlank() || line.startsWith("#")) continue;
            boolean cont = line.startsWith("> ");
            String[] qa = (cont ? line.substring(2) : line).split("=>");
            String q = qa[0].trim(), exp = qa[1].trim();
            if (!cont) brain.reset();
            Brain.Reply r = brain.ask(q);
            boolean noAns = r.kind == Brain.Reply.Kind.FALLBACK || r.kind == Brain.Reply.Kind.CLARIFY;
            boolean okNew, okOld;
            String oldAns = old.answer(q);
            boolean oldNoAns = oldAns.startsWith("Je n’ai pas trouvé") || oldAns.startsWith("Je ne suis pas sûr") || oldAns.startsWith("Pouvez-vous");
            if (exp.equals("~SMALLTALK")) {
                okNew = r.kind == Brain.Reply.Kind.SMALLTALK;
                okOld = !oldNoAns;
            } else if (exp.equals("~NOANSWER")) {
                okNew = noAns;
                okOld = oldNoAns;
            } else {
                Pattern p = Pattern.compile(fold(exp));
                okNew = !noAns && p.matcher(fold(r.text)).find();
                okOld = !oldNoAns && p.matcher(fold(oldAns)).find();
            }
            n++; if (okNew) okN++; if (okOld) okO++;
            int[] c = cat.computeIfAbsent(section, k -> new int[3]);
            c[0]++; if (okNew) c[1]++; if (okOld) c[2]++;
            if (!okNew) fails.add((cont ? "  > " : "") + q + "   [attendu " + exp + "]\n      -> " + r.kind + " "
                    + String.format("%.2f", r.confidence) + " : " + r.text.replace("\n", " / ").substring(0, Math.min(150, r.text.length())));
            if (verbose && okNew) System.out.println("OK  " + q + " -> " + r.text.replace("\n", " / ").substring(0, Math.min(90, r.text.length())));
        }
        long ms = (System.nanoTime() - t0) / 1_000_000;
        System.out.println("\n=== Échecs du nouveau moteur ===");
        for (String f : fails) System.out.println(f);
        System.out.printf("%n%-42s %6s %10s %10s%n", "Catégorie", "Quest.", "Nouveau", "Ancien");
        for (Map.Entry<String, int[]> e : cat.entrySet()) {
            int[] c = e.getValue();
            System.out.printf("%-42s %6d %9d%% %9d%%%n", e.getKey(), c[0], 100 * c[1] / c[0], 100 * c[2] / c[0]);
        }
        System.out.printf("%-42s %6d %9d%% %9d%%%n", "TOTAL", n, 100 * okN / n, 100 * okO / n);
        System.out.println("(" + okN + " / " + n + " pour le nouveau moteur, " + okO + " / " + n + " pour l'ancien ; " + ms + " ms en tout)");
    }
}
