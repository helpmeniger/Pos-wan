package com.hightechniger.help_me;

import android.content.DialogInterface;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.widget.Toast;
import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;
import com.github.barteksc.pdfviewer.PDFView;

public class MainActivity6 extends AppCompatActivity {

    private static final String PREFS_NAME = "HelpMePrefs";
    private static final String PREFS_PAGE_KEY = "currentPage";
    private static final String CORRECT_PASSWORD = BuildConfig.HM_MANUAL_PASSWORD; // Mot de passe sécurisé
    private PDFView pdfView;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main6);

        // Initialiser le PDFView
        pdfView = findViewById(R.id.pdfView);

        // Demander le mot de passe avant de charger le PDF
        showPasswordDialog();
    }

    // Méthode pour afficher une boîte de dialogue de mot de passe
    private void showPasswordDialog() {
        AlertDialog.Builder builder = new AlertDialog.Builder(this);
        builder.setTitle("Entrez le mot de passe pour des raisons de sécurité. Sinon, contactez Help-me.");

        // Champ de texte pour le mot de passe
        final android.widget.EditText input = new android.widget.EditText(this);
        input.setHint("Mot de passe");
        input.setInputType(android.text.InputType.TYPE_CLASS_TEXT | android.text.InputType.TYPE_TEXT_VARIATION_PASSWORD);
        builder.setView(input);

        // Boutons de la boîte de dialogue
        builder.setPositiveButton("OK", new DialogInterface.OnClickListener() {
            @Override
            public void onClick(DialogInterface dialog, int which) {
                String enteredPassword = input.getText().toString();

                if (enteredPassword.equals(CORRECT_PASSWORD)) {
                    // Mot de passe correct : Charger le fichier PDF
                    loadPDF();
                } else {
                    // Mot de passe incorrect : Afficher un message et redemander
                    Toast.makeText(MainActivity6.this, "Mot de passe incorrect", Toast.LENGTH_SHORT).show();
                    showPasswordDialog(); // Redemander le mot de passe
                }
            }
        });

        builder.setNegativeButton("Annuler", new DialogInterface.OnClickListener() {
            @Override
            public void onClick(DialogInterface dialog, int which) {
                // Fermer l'activité si l'utilisateur annule
                finish();
            }
        });

        builder.setCancelable(false); // Empêcher la fermeture de la boîte de dialogue sans action
        builder.show();
    }

    // Méthode pour charger le fichier PDF avec la page sauvegardée
    private void loadPDF() {
        SharedPreferences prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
        int savedPage = prefs.getInt(PREFS_PAGE_KEY, 0); // 0 par défaut si aucune page n'est sauvegardée

        pdfView.fromAsset("bookone.pdf")
                .enableDoubletap(true) // Permettre le zoom avec double clic
                .defaultPage(savedPage) // Charger la page sauvegardée
                .enableSwipe(true) // Permettre le balayage horizontal ou vertical
                .swipeHorizontal(true) // Balayage horizontal pour une lecture de type livre
                .enableAnnotationRendering(true) // Afficher les annotations
                .scrollHandle(null) // Désactiver la barre de défilement
                .onPageChange((page, pageCount) -> {
                    // Sauvegarder la page actuelle dans les SharedPreferences
                    SharedPreferences.Editor editor = prefs.edit();
                    editor.putInt(PREFS_PAGE_KEY, page);
                    editor.apply();
                })
                .load();
    }

    @Override
    protected void onPause() {
        super.onPause();
        // Sauvegarder la page actuelle lors de la pause de l'activité
        if (pdfView != null) {
            int currentPage = pdfView.getCurrentPage();
            SharedPreferences prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
            SharedPreferences.Editor editor = prefs.edit();
            editor.putInt(PREFS_PAGE_KEY, currentPage);
            editor.apply();
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        // Recharger la dernière page à partir des SharedPreferences si le PDF est chargé
        SharedPreferences prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
        int savedPage = prefs.getInt(PREFS_PAGE_KEY, 0); // Page par défaut si aucune page enregistrée
        if (pdfView != null) {
            pdfView.jumpTo(savedPage, true); // Aller à la page sauvegardée
        }
    }
}
