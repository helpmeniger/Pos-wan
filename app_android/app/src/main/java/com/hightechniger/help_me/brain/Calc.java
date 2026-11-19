package com.hightechniger.help_me.brain;

import java.text.DecimalFormat;
import java.text.DecimalFormatSymbols;
import java.util.Locale;

/** Petite calculatrice : + - * x / ÷ ^ %, parenthèses, virgule décimale. « 15 % de 2000 » est accepté. */
final class Calc {
    private final String s;
    private int i;

    private Calc(String s) { this.s = s; }

    static Double eval(String expr) {
        String e = expr.toLowerCase(Locale.ROOT)
                .replaceAll("(\\d)\\s*%\\s*de\\s*", "$1%*")
                .replace("×", "*").replace("x", "*").replace("÷", "/").replace(",", ".").replaceAll("\\s+", "");
        try {
            Calc c = new Calc(e);
            double v = c.sum();
            if (c.i != e.length() || Double.isNaN(v) || Double.isInfinite(v)) return null;
            return v;
        } catch (RuntimeException ex) {
            return null;
        }
    }

    static String format(double v) {
        DecimalFormat f = new DecimalFormat("#,##0.####", new DecimalFormatSymbols(Locale.FRANCE));
        return f.format(v).replace(' ', ' ').replace(' ', ' ');
    }

    private double sum() {
        double v = product();
        while (i < s.length()) {
            char c = s.charAt(i);
            if (c == '+') { i++; v += product(); } else if (c == '-') { i++; v -= product(); } else break;
        }
        return v;
    }

    private double product() {
        double v = power();
        while (i < s.length()) {
            char c = s.charAt(i);
            if (c == '*') { i++; v *= power(); } else if (c == '/') { i++; v /= power(); } else break;
        }
        return v;
    }

    private double power() {
        double v = unary();
        if (i < s.length() && s.charAt(i) == '^') { i++; v = Math.pow(v, power()); }
        return v;
    }

    private double unary() {
        if (i < s.length() && s.charAt(i) == '-') { i++; return -unary(); }
        if (i < s.length() && s.charAt(i) == '+') { i++; return unary(); }
        double v;
        if (i < s.length() && s.charAt(i) == '(') {
            i++;
            v = sum();
            if (i >= s.length() || s.charAt(i) != ')') throw new IllegalStateException();
            i++;
        } else {
            int st = i;
            while (i < s.length() && (Character.isDigit(s.charAt(i)) || s.charAt(i) == '.')) i++;
            if (st == i) throw new IllegalStateException();
            v = Double.parseDouble(s.substring(st, i));
        }
        if (i < s.length() && s.charAt(i) == '%') { i++; v /= 100; }
        return v;
    }
}
