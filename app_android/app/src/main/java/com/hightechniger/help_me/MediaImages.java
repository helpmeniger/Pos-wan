package com.hightechniger.help_me;

import android.content.Context;

/**
 * Remplacement provisoire des vidéos : chaque ancienne vidéo (vid1, puce, formuleexcel…)
 * est remplacée par l'illustration correspondante du manuel, res/drawable-nodpi/lecon_<nom>.webp.
 * Quand les vidéos seront prêtes, il suffira de les remettre dans res/raw et de revenir à la lecture vidéo.
 */
public final class MediaImages {
    private MediaImages() {}

    /** Identifiant de l'image qui remplace la vidéo « name », ou 0 si aucune. */
    public static int image(Context c, String name) {
        return c.getResources().getIdentifier("lecon_" + name, "drawable", c.getPackageName());
    }
}
