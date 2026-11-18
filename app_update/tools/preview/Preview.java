import com.hightechniger.help_me.demo.CreerOrganiserScene;
import com.hightechniger.help_me.demo.DemoScene;

import java.awt.*;
import java.awt.image.BufferedImage;
import java.io.OutputStream;

/** Rend une scène en vidéo MP4 (aperçu) : java Preview sortie.mp4 [fps] */
public class Preview {
    public static void main(String[] a) throws Exception {
        DemoScene s = new CreerOrganiserScene();
        int fps = a.length > 1 ? Integer.parseInt(a[1]) : 15, W = 1280, H = 720, CAP = 90;
        Process ff = new ProcessBuilder("ffmpeg", "-y", "-loglevel", "error", "-f", "rawvideo", "-pix_fmt", "rgb24",
                "-s", W + "x" + (H + CAP), "-r", "" + fps, "-i", "-", "-c:v", "libx264", "-pix_fmt", "yuv420p",
                "-crf", "26", "-preset", "veryfast", a[0]).inheritIO().redirectInput(ProcessBuilder.Redirect.PIPE).start();
        OutputStream out = ff.getOutputStream();
        BufferedImage img = new BufferedImage(W, H + CAP, BufferedImage.TYPE_3BYTE_BGR);
        byte[] rgb = new byte[W * (H + CAP) * 3];
        int n = Math.round(s.duration() * fps);
        for (int i = 0; i <= n; i++) {
            float t = i / (float) fps;
            Graphics2D g = img.createGraphics();
            AwtGfx gx = new AwtGfx(g);
            s.draw(gx, t);
            gx.rect(0, H, W, CAP, 0xFF1C2433);
            gx.text(s.caption(t), W / 2f, H + 52, 24, 0xFFFFFFFF, false, 1);
            g.dispose();
            byte[] bgr = ((java.awt.image.DataBufferByte) img.getRaster().getDataBuffer()).getData();
            for (int p = 0; p < bgr.length; p += 3) { rgb[p] = bgr[p + 2]; rgb[p + 1] = bgr[p + 1]; rgb[p + 2] = bgr[p]; }
            out.write(rgb);
        }
        out.close();
        ff.waitFor();
    }
}
