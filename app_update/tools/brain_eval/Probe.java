import com.hightechniger.help_me.brain.*;
import java.nio.file.*;

/** Conversation en ligne de commande : java Probe "question 1" "question 2" ... */
public class Probe {
    public static void main(String[] a) throws Exception {
        String json = new String(Files.readAllBytes(Paths.get(System.getProperty("brain"))), "UTF-8");
        Brain b = Brain.fromJson(json, null);
        for (String q : a) {
            if (q.startsWith("?")) { System.out.print(b.debug(q.substring(1), 8)); continue; }
            Brain.Reply r = q.startsWith("#") ? b.open(Integer.parseInt(q.substring(1))) : b.ask(q);
            System.out.println("Q: " + q + "\n  [" + r.kind + " conf=" + String.format("%.2f", r.confidence) + " doc=" + r.docId + "] "
                + r.text.replace("\n", " / ").substring(0, Math.min(220, r.text.length()))
                + (r.note != null ? "\n  note: " + r.note.replace("\n", " | ") : "")
                + (r.chips.isEmpty() ? "" : "\n  " + r.chipsTitle + " " + r.chips));
        }
    }
}
