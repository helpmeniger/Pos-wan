import com.hightechniger.help_me.demo.Gfx;

import java.awt.*;
import java.awt.geom.*;
import java.util.ArrayDeque;

/** Implémentation Java2D de Gfx, pour l'aperçu hors Android. */
public class AwtGfx implements Gfx {
    private final Graphics2D g;
    private final ArrayDeque<AffineTransform> tx = new ArrayDeque<>();
    private final ArrayDeque<Shape> clips = new ArrayDeque<>();

    public AwtGfx(Graphics2D g) {
        this.g = g;
        g.setRenderingHint(RenderingHints.KEY_ANTIALIASING, RenderingHints.VALUE_ANTIALIAS_ON);
        g.setRenderingHint(RenderingHints.KEY_TEXT_ANTIALIASING, RenderingHints.VALUE_TEXT_ANTIALIAS_ON);
        g.setRenderingHint(RenderingHints.KEY_FRACTIONALMETRICS, RenderingHints.VALUE_FRACTIONALMETRICS_ON);
    }

    private Color c(int argb) { return new Color(argb, true); }
    private Font f(float size, boolean bold) { return new Font("Inter", bold ? Font.BOLD : Font.PLAIN, 1).deriveFont(size); }

    public void rect(float x, float y, float w, float h, int a) { g.setColor(c(a)); g.fill(new Rectangle2D.Float(x, y, w, h)); }
    public void roundRect(float x, float y, float w, float h, float r, int a) { g.setColor(c(a)); g.fill(new RoundRectangle2D.Float(x, y, w, h, 2 * r, 2 * r)); }
    public void strokeRoundRect(float x, float y, float w, float h, float r, float s, int a) {
        g.setColor(c(a)); g.setStroke(new BasicStroke(s)); g.draw(new RoundRectangle2D.Float(x, y, w, h, 2 * r, 2 * r)); }
    public void gradientRect(float x, float y, float w, float h, int t, int b) {
        g.setPaint(new GradientPaint(x, y, c(t), x, y + h, c(b))); g.fill(new Rectangle2D.Float(x, y, w, h)); }
    public void line(float x1, float y1, float x2, float y2, float s, int a) {
        g.setColor(c(a)); g.setStroke(new BasicStroke(s, BasicStroke.CAP_ROUND, BasicStroke.JOIN_ROUND)); g.draw(new Line2D.Float(x1, y1, x2, y2)); }
    public void circle(float cx, float cy, float r, int a) { g.setColor(c(a)); g.fill(new Ellipse2D.Float(cx - r, cy - r, 2 * r, 2 * r)); }
    public void strokeCircle(float cx, float cy, float r, float s, int a) {
        g.setColor(c(a)); g.setStroke(new BasicStroke(s)); g.draw(new Ellipse2D.Float(cx - r, cy - r, 2 * r, 2 * r)); }
    public void polygon(float[] xy, int a) {
        Path2D.Float p = new Path2D.Float(); p.moveTo(xy[0], xy[1]);
        for (int i = 2; i < xy.length; i += 2) p.lineTo(xy[i], xy[i + 1]);
        p.closePath(); g.setColor(c(a)); g.fill(p); }
    public void text(String s, float x, float y, float size, int a, boolean bold, int align) {
        g.setFont(f(size, bold)); g.setColor(c(a));
        float w = (float) g.getFontMetrics().getStringBounds(s, g).getWidth();
        float dx = align == CENTER ? -w / 2 : align == RIGHT ? -w : 0;
        g.drawString(s, x + dx, y); }
    public float textWidth(String s, float size, boolean bold) {
        g.setFont(f(size, bold)); return (float) g.getFontMetrics().getStringBounds(s, g).getWidth(); }
    public void save() { tx.push(g.getTransform()); clips.push(g.getClip() == null ? new Rectangle(-100000, -100000, 200000, 200000) : g.getClip()); }
    public void restore() { g.setTransform(tx.pop()); g.setClip(clips.pop()); }
    public void translate(float dx, float dy) { g.translate(dx, dy); }
    public void scale(float s, float px, float py) { g.translate(px, py); g.scale(s, s); g.translate(-px, -py); }
    public void clip(float x, float y, float w, float h) { g.clip(new Rectangle2D.Float(x, y, w, h)); }
}
