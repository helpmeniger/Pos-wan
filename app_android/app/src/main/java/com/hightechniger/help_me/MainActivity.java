package com.hightechniger.help_me;

import android.Manifest;
import android.app.ProgressDialog;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.hardware.Sensor;
import android.hardware.SensorEvent;
import android.hardware.SensorEventListener;
import android.hardware.SensorManager;
import android.net.Uri;
import android.os.Bundle;
import android.view.MenuItem;
import android.view.MotionEvent;
import android.view.View;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import androidx.annotation.NonNull;
import androidx.appcompat.app.ActionBarDrawerToggle;
import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;
import androidx.appcompat.widget.Toolbar;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;
import androidx.core.view.GravityCompat;
import androidx.drawerlayout.widget.DrawerLayout;

import com.google.android.material.navigation.NavigationView;
import com.google.firebase.auth.FirebaseAuth;
import com.google.firebase.auth.FirebaseUser;
import com.google.firebase.firestore.FirebaseFirestore;

import java.io.File;
import java.util.Calendar;
import java.util.concurrent.TimeUnit;

public class MainActivity extends AppCompatActivity implements NavigationView.OnNavigationItemSelectedListener, SensorEventListener {

    private static final int REQUEST_CALL_PERMISSION = 1;
    private static final String PHONE_NUMBER = "+22792081282";

    private DrawerLayout drawerLayout;
    private int tapCount = 0;
    private static final int TAP_THRESHOLD = 3;

    private SensorManager sensorManager;
    private Sensor accelerometer;
    private float lastX, lastY, lastZ;
    private long lastUpdateTime;
    private static final int SHAKE_THRESHOLD = 1200;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        Toolbar toolbar = findViewById(R.id.toolbar);
        setSupportActionBar(toolbar);

        drawerLayout = findViewById(R.id.drawer_layout);
        NavigationView navigationView = findViewById(R.id.nav_view);
        navigationView.setNavigationItemSelectedListener(this);

        ActionBarDrawerToggle toggle = new ActionBarDrawerToggle(
                this, drawerLayout, toolbar, R.string.open_nav, R.string.close_nav);
        drawerLayout.addDrawerListener(toggle);
        toggle.syncState();

        if (savedInstanceState == null) {
            getSupportFragmentManager().beginTransaction()
                    .replace(R.id.fragment_container, new HomeFragment()).commit();
            navigationView.setCheckedItem(R.id.nav_home);
        }

        initializeTapDetection();
        initializeFirstUseDate();

        sensorManager = (SensorManager) getSystemService(SENSOR_SERVICE);
        accelerometer = sensorManager.getDefaultSensor(Sensor.TYPE_ACCELEROMETER);

        if (accelerometer == null) {
            Toast.makeText(this, "Accéléromètre non disponible", Toast.LENGTH_SHORT).show();
        }
    }

    @Override
    public boolean onNavigationItemSelected(@NonNull MenuItem item) {
        switch (item.getItemId()) {
            case R.id.nav_home:
                getSupportFragmentManager().beginTransaction()
                        .replace(R.id.fragment_container, new HomeFragment()).commit();
                break;

            case R.id.nav_settings:
                startActivity(new Intent(MainActivity.this, MainActivity7.class));
                break;

            case R.id.nav_scan:
                startActivity(new Intent(MainActivity.this, MainActivity8.class));
                break;


            case R.id.anti_stress:
                startActivity(new Intent(MainActivity.this, MiniGameActivity.class));
                break;

            case R.id.nav_num:
                startActivity(new Intent(MainActivity.this, MainActivity9.class));
                break;

            case R.id.nav_cert:
                if (isEligibleForRestrictedActivity()) {
                    startActivity(new Intent(MainActivity.this, MainActivity16.class));
                } else {
                    Toast.makeText(this,
                            "Vous devez attendre encore " + getDaysLeft() + " jours.",
                            Toast.LENGTH_LONG).show();
                }
                break;

            case R.id.nav_forum:
                startActivity(new Intent(MainActivity.this, ForumActivity.class));
                break;

            case R.id.assist:
                showCallWarning();
                break;

            case R.id.nav_propo:
                startActivity(new Intent(MainActivity.this, WordActivity9.class));
                break;

            case R.id.nav_mise:
                startActivity(new Intent(MainActivity.this, MiseActivity9.class));
                break;

            case R.id.nav_logout:
                showLogoutDialog();
                break;
        }

        drawerLayout.closeDrawer(GravityCompat.START);
        return true;
    }

    private void showLogoutDialog() {
        new AlertDialog.Builder(this)
                .setTitle("Déconnexion")
                .setMessage("Êtes-vous sûr de vouloir vous déconnecter ? Votre compte pourrait être désactivé.")
                .setPositiveButton("Oui", (dialog, which) -> {

                    FirebaseUser user = FirebaseAuth.getInstance().getCurrentUser();

                    ProgressDialog progressDialog = new ProgressDialog(this);
                    progressDialog.setMessage("Déconnexion...");
                    progressDialog.setCancelable(false);
                    progressDialog.show();

                    if (user != null) {
                        FirebaseFirestore db = FirebaseFirestore.getInstance();

                        db.collection("users")
                                .document(user.getUid())
                                .update("isOnline", false, "isActive", false)
                                .addOnSuccessListener(aVoid -> {
                                    clearLocalAccountData();
                                    FirebaseAuth.getInstance().signOut();
                                    progressDialog.dismiss();
                                    redirectToLogin();
                                })
                                .addOnFailureListener(e -> {
                                    progressDialog.dismiss();
                                    Toast.makeText(this, "Erreur réseau, déconnexion forcée.", Toast.LENGTH_SHORT).show();
                                    clearLocalAccountData();
                                    FirebaseAuth.getInstance().signOut();
                                    redirectToLogin();
                                });

                    } else {
                        clearLocalAccountData();
                        FirebaseAuth.getInstance().signOut();
                        redirectToLogin();
                    }

                })
                .setNegativeButton("Non", (dialog, which) -> dialog.dismiss())
                .show();
    }

    private void clearLocalAccountData() {
        try {
            getSharedPreferences("APP_PREFS", MODE_PRIVATE).edit().clear().apply();

            FirebaseFirestore.getInstance().clearPersistence();
            deleteCacheDir();

            File appDir = getFilesDir();
            if (appDir != null && appDir.isDirectory()) {
                for (File child : appDir.listFiles()) {
                    child.delete();
                }
            }

            File extCache = getExternalCacheDir();
            if (extCache != null && extCache.isDirectory()) {
                for (File child : extCache.listFiles()) {
                    child.delete();
                }
            }

            Toast.makeText(this, "Merci de contacter notre service d’assistance.", Toast.LENGTH_SHORT).show();
        } catch (Exception e) {
            Toast.makeText(this, "Erreur pendant le nettoyage local.", Toast.LENGTH_SHORT).show();
        }
    }

    private void deleteCacheDir() {
        try {
            File cacheDir = getCacheDir();
            if (cacheDir != null && cacheDir.isDirectory()) {
                for (File child : cacheDir.listFiles()) {
                    child.delete();
                }
            }
        } catch (Exception e) {
            // Ignorer
        }
    }

    private void redirectToLogin() {
        Intent intent = new Intent(this, Login.class);
        intent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP |
                Intent.FLAG_ACTIVITY_NEW_TASK |
                Intent.FLAG_ACTIVITY_CLEAR_TASK);
        startActivity(intent);
        finish();
    }

    private void initializeTapDetection() {
        LinearLayout mainLayout = findViewById(R.id.layout1);
        if (mainLayout == null) return; // sécurité

        mainLayout.setOnTouchListener((v, event) -> {
            if (event.getAction() == MotionEvent.ACTION_DOWN) {
                tapCount++;
                mainLayout.postDelayed(() -> tapCount = 0, 2000);
                if (tapCount == TAP_THRESHOLD) {
                    tapCount = 0;
                    startActivity(new Intent(MainActivity.this, MainActivity8.class));
                }
            }
            return true;
        });
    }

    private void initializeFirstUseDate() {
        SharedPreferences prefs = getSharedPreferences("UsagePrefs", MODE_PRIVATE);
        if (!prefs.contains("FirstUseDate")) {
            prefs.edit().putLong("FirstUseDate",
                    Calendar.getInstance().getTimeInMillis()).apply();
        }
    }

    private boolean isEligibleForRestrictedActivity() {
        SharedPreferences prefs = getSharedPreferences("UsagePrefs", MODE_PRIVATE);
        long first = prefs.getLong("FirstUseDate", 0);
        return TimeUnit.MILLISECONDS.toDays(
                Calendar.getInstance().getTimeInMillis() - first) >= 30;
    }

    private int getDaysLeft() {
        SharedPreferences prefs = getSharedPreferences("UsagePrefs", MODE_PRIVATE);
        long first = prefs.getLong("FirstUseDate", 0);
        long passed = TimeUnit.MILLISECONDS.toDays(
                Calendar.getInstance().getTimeInMillis() - first);
        return Math.max(0, 30 - (int) passed);
    }

    private void showCallWarning() {
        new AlertDialog.Builder(this)
                .setTitle("Avertissement")
                .setMessage("Ce service peut être facturé. Continuer ?")
                .setPositiveButton("Oui", (d, w) -> checkCallPermission())
                .setNegativeButton("Non", (d, w) -> d.dismiss())
                .show();
    }

    private void checkCallPermission() {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CALL_PHONE)
                == PackageManager.PERMISSION_GRANTED) {
            makePhoneCall();
        } else {
            ActivityCompat.requestPermissions(
                    this,
                    new String[]{Manifest.permission.CALL_PHONE},
                    REQUEST_CALL_PERMISSION
            );
        }
    }

    private boolean isCallCapable() {
        return getPackageManager().hasSystemFeature(PackageManager.FEATURE_TELEPHONY);
    }

    @Override
    protected void onResume() {
        super.onResume();

        // 🔥 Mise à jour du badge Forum de façon SÉCURISÉE
        NavigationView navigationView = findViewById(R.id.nav_view);
        if (navigationView != null) {
            MenuItem forumItem = navigationView.getMenu().findItem(R.id.nav_forum);
            if (forumItem != null) {
                View actionView = forumItem.getActionView();
                TextView badge;

                // Si root de actionLayout est directement le TextView
                if (actionView instanceof TextView) {
                    badge = (TextView) actionView;
                } else if (actionView != null) {
                    badge = actionView.findViewById(R.id.forum_badge);
                } else {
                    badge = null;
                }

                if (badge != null) {
                    int unread = ForumActivity.unreadCount;

                    if (unread > 0) {
                        badge.setText(String.valueOf(unread));
                        badge.setVisibility(View.VISIBLE);
                    } else {
                        badge.setVisibility(View.GONE);
                    }
                }
            }
        }
    }
    private void updateForumBadge() {

        NavigationView navigationView = findViewById(R.id.nav_view);
        if (navigationView == null) return;

        MenuItem forumItem = navigationView.getMenu().findItem(R.id.nav_forum);
        if (forumItem == null) return;

        TextView badge;

        View view = forumItem.getActionView();

        if (view instanceof TextView) {
            badge = (TextView) view;
        } else if (view != null) {
            badge = view.findViewById(R.id.forum_badge);
        } else {
            return;
        }

        int unread = ForumActivity.unreadCount;

        if (unread > 0) {
            badge.setVisibility(View.VISIBLE);
            badge.setText(String.valueOf(unread));
        } else {
            badge.setVisibility(View.GONE);
        }
    }

    private void makePhoneCall() {
        if (!isCallCapable()) {
            Toast.makeText(this, "Appels impossibles.", Toast.LENGTH_SHORT).show();
            return;
        }

        startActivity(new Intent(Intent.ACTION_CALL, Uri.parse("tel:" + PHONE_NUMBER)));
    }

    @Override public void onSensorChanged(SensorEvent e) {}
    @Override public void onAccuracyChanged(Sensor s, int a) {}
}
