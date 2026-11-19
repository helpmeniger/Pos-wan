package com.hightechniger.help_me;

import android.Manifest;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.ConnectivityManager;
import android.net.NetworkInfo;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.DownloadListener;
import android.webkit.URLUtil;
import android.webkit.WebChromeClient;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;

public class MainActivity10 extends AppCompatActivity {

    private WebView webView;
    private ProgressBar progressBar;
    private TextView waitMessage;

    private static final int PERMISSION_REQUEST_CODE = 100;

    private String pendingDownloadUrl;
    private String pendingUserAgent;
    private String pendingContentDisposition;
    private String pendingMimeType;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main10);

        webView = findViewById(R.id.webView);
        progressBar = findViewById(R.id.progressBar);
        waitMessage = findViewById(R.id.pleaseWaitMessage);

        // Assure la plage correcte de la progressbar
        progressBar.setMax(100);
        progressBar.setProgress(0);

        // Vérifie la connexion Internet
        if (!isConnected()) {
            Toast.makeText(this, "Vérifiez votre connexion Internet SVP", Toast.LENGTH_LONG).show();
            return;
        }

        // Demande de permission si nécessaire (pour les anciennes versions)
        if (!checkStoragePermission()) {
            requestStoragePermission();
        }

        // Configuration WebView
        WebSettings webSettings = webView.getSettings();
        webSettings.setJavaScriptEnabled(true);
        webSettings.setBuiltInZoomControls(true);
        webSettings.setDisplayZoomControls(false);
        webSettings.setLoadWithOverviewMode(true);
        webSettings.setUseWideViewPort(true);
        webSettings.setDomStorageEnabled(true);
        webSettings.setAllowFileAccess(true);
        webSettings.setAllowContentAccess(true);

        // WebViewClient (support pour API récente et ancienne)
        webView.setWebViewClient(new WebViewClient() {
            // pour anciennes API
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, String url) {
                view.loadUrl(url);
                return true;
            }

            // pour API >= 24 tu peux ajouter l'autre override si nécessaire
        });

        // WebChromeClient : gestion du progress
        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int newProgress) {
                // Met à jour la progressbar et le message d'attente
                if (newProgress < 100) {
                    if (progressBar.getVisibility() != View.VISIBLE) progressBar.setVisibility(View.VISIBLE);
                    progressBar.setProgress(newProgress);
                    if (waitMessage.getVisibility() != View.VISIBLE) waitMessage.setVisibility(View.VISIBLE);
                } else {
                    progressBar.setVisibility(View.GONE);
                    waitMessage.setVisibility(View.GONE);
                }
            }
        });

        // DownloadListener : vérifie le schéma URL et télécharge seulement si HTTP/HTTPS
        webView.setDownloadListener(new DownloadListener() {
            @Override
            public void onDownloadStart(String url, String userAgent, String contentDisposition, String mimeType, long contentLength) {
                if (url == null) {
                    Toast.makeText(MainActivity10.this, "URL invalide", Toast.LENGTH_SHORT).show();
                    return;
                }

                // Si c'est une URL HTTP/HTTPS -> DownloadManager
                if (URLUtil.isHttpUrl(url) || URLUtil.isHttpsUrl(url)) {
                    if (checkStoragePermission()) {
                        handleDownload(url, userAgent, contentDisposition, mimeType);
                    } else {
                        // Mémorise et demande permission
                        pendingDownloadUrl = url;
                        pendingUserAgent = userAgent;
                        pendingContentDisposition = contentDisposition;
                        pendingMimeType = mimeType;
                        Toast.makeText(MainActivity10.this, "Permission requise pour télécharger le fichier", Toast.LENGTH_LONG).show();
                        requestStoragePermission();
                    }
                } else {
                    // schéma non supporté par DownloadManager, on essaye d'ouvrir avec une app externe (navigateur / viewer)
                    try {
                        Intent intent = new Intent(Intent.ACTION_VIEW);
                        intent.setData(Uri.parse(url));
                        // Add FLAG_ACTIVITY_NEW_TASK si nécessaire dans certains cas
                        startActivity(intent);
                    } catch (ActivityNotFoundException ex) {
                        Toast.makeText(MainActivity10.this, "Impossible de télécharger ce type de ressource: " + url, Toast.LENGTH_LONG).show();
                    } catch (Exception ex) {
                        Toast.makeText(MainActivity10.this, "Erreur lors de l'ouverture du lien: " + ex.getMessage(), Toast.LENGTH_LONG).show();
                    }
                }
            }
        });

        // Charge l'URL (modifiable)
        webView.loadUrl("https://create.microsoft.com/en-us");
    }

    // Vérifie la connexion Internet
    private boolean isConnected() {
        ConnectivityManager cm = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
        if (cm != null) {
            NetworkInfo info = cm.getActiveNetworkInfo();
            return info != null && info.isConnected();
        }
        return false;
    }

    // Téléchargement via DownloadManager (seulement HTTP/HTTPS)
    private void handleDownload(String url, String userAgent, String contentDisposition, String mimeType) {
        try {
            Uri downloadUri = Uri.parse(url);

            // sécurité: vérifie encore le schéma
            if (!URLUtil.isHttpUrl(url) && !URLUtil.isHttpsUrl(url)) {
                Toast.makeText(this, "Le téléchargement direct n'est pas supporté pour ce type d'URL.", Toast.LENGTH_LONG).show();
                return;
            }

            DownloadManager downloadManager = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
            if (downloadManager == null) {
                throw new IllegalStateException("Service de téléchargement non disponible");
            }

            // devine le nom du fichier
            String fileName = URLUtil.guessFileName(url, contentDisposition, mimeType);
            if (fileName == null || fileName.trim().isEmpty()) {
                fileName = "fichier_" + System.currentTimeMillis();
            }

            DownloadManager.Request request = new DownloadManager.Request(downloadUri);
            request.setMimeType(mimeType);
            request.addRequestHeader("User-Agent", userAgent != null ? userAgent : "");
            // ajoute les cookies si présent pour les ressources protégées
            String cookie = CookieManager.getInstance().getCookie(url);
            if (cookie != null) {
                request.addRequestHeader("cookie", cookie);
            }
            request.setDescription("Téléchargement en cours...");
            request.setTitle(fileName);
            request.allowScanningByMediaScanner();
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);

            // destination : Downloads
            // Note: setDestinationInExternalPublicDir est déprécié mais fonctionne encore ; pour Android Q+ il faudrait utiliser MediaStore/SAF pour un contrôle plus fin.
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName);

            downloadManager.enqueue(request);
            Toast.makeText(this, "Téléchargement démarré...", Toast.LENGTH_SHORT).show();
        } catch (IllegalArgumentException iae) {
            // Cas où DownloadManager refuse la requête (par ex. schéma non supporté)
            Toast.makeText(this, "Impossible de télécharger : URL non prise en charge.", Toast.LENGTH_LONG).show();
        } catch (Exception e) {
            Toast.makeText(this, "Erreur de téléchargement : " + e.getMessage(), Toast.LENGTH_LONG).show();
        }
    }

    // Permission stockage (vérification)
    private boolean checkStoragePermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            // Android 10+ n'a pas besoin de WRITE_EXTERNAL_STORAGE pour l'écriture dans Downloads via DownloadManager
            return true;
        } else {
            return ContextCompat.checkSelfPermission(this, Manifest.permission.WRITE_EXTERNAL_STORAGE)
                    == PackageManager.PERMISSION_GRANTED;
        }
    }

    // Demande permission (anciennes versions)
    private void requestStoragePermission() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) {
            ActivityCompat.requestPermissions(this,
                    new String[]{Manifest.permission.WRITE_EXTERNAL_STORAGE},
                    PERMISSION_REQUEST_CODE);
        }
    }

    // Résultat de la demande de permission
    @Override
    public void onRequestPermissionsResult(int requestCode, @NonNull String[] permissions, @NonNull int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == PERMISSION_REQUEST_CODE) {
            if (grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                Toast.makeText(this, "Permission accordée", Toast.LENGTH_SHORT).show();
                // relancer le téléchargement en attente si existant
                if (pendingDownloadUrl != null) {
                    handleDownload(pendingDownloadUrl, pendingUserAgent, pendingContentDisposition, pendingMimeType);
                    pendingDownloadUrl = null;
                    pendingUserAgent = null;
                    pendingContentDisposition = null;
                    pendingMimeType = null;
                }
            } else {
                Toast.makeText(this, "Permission refusée. Impossible de télécharger.", Toast.LENGTH_SHORT).show();
            }
        }
    }

    @Override
    public void onBackPressed() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
        } else {
            super.onBackPressed();
        }
    }
}
