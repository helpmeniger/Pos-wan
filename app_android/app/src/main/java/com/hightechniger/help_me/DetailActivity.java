package com.hightechniger.help_me;

import android.graphics.drawable.Drawable;
import android.os.Bundle;
import android.text.Html;
import android.widget.TextView;

import androidx.appcompat.app.AppCompatActivity;

public class DetailActivity extends AppCompatActivity {

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_detail); // Assurez-vous que ce fichier correspond bien à votre layout XML

        // Récupérer les extras passés par l'intent
        String title = getIntent().getStringExtra("title");
        String content = getIntent().getStringExtra("content");

        // Associer les TextViews
        TextView titleTextView = findViewById(R.id.titleTextView);
        TextView contentTextView = findViewById(R.id.contentTextView);

        // Mettre à jour le titre
        if (title != null) {
            titleTextView.setText(title);
        }

        // Appliquer la mise en forme HTML et charger les images
        if (content != null) {
            contentTextView.setText(Html.fromHtml(
                    content,
                    Html.FROM_HTML_MODE_COMPACT,
                    source -> {
                        // Charger l'image depuis res/drawable
                        int resId = getResources().getIdentifier(source, "drawable", getPackageName());
                        if (resId != 0) {
                            Drawable drawable = getResources().getDrawable(resId, null);
                            drawable.setBounds(0, 0, drawable.getIntrinsicWidth(), drawable.getIntrinsicHeight());
                            return drawable;
                        }
                        return null;
                    },
                    null
            ));
        }
    }
}
