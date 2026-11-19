import com.hightechniger.help_me.brain.Brain;
import java.nio.file.*;
/** Temps de chargement et de réponse du moteur. */
public class Bench {
    public static void main(String[] a) throws Exception {
        String json = new String(Files.readAllBytes(Paths.get(System.getProperty("brain"))), "UTF-8");
        for (int i = 0; i < 3; i++) {
            long t = System.nanoTime();
            Brain b = Brain.fromJson(json, null);
            long t1 = System.nanoTime();
            for (int k = 0; k < 50; k++) b.ask("comment fair une somme sur exel avec une formule");
            long t2 = System.nanoTime();
            System.out.printf("chargement %d ms, réponse %.1f ms%n", (t1 - t) / 1000000, (t2 - t1) / 50e6);
        }
    }
}
