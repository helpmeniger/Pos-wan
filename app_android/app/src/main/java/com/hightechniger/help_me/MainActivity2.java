package com.hightechniger.help_me;

import android.annotation.SuppressLint;
import android.content.Intent;
import android.content.SharedPreferences;
import android.hardware.Sensor;
import android.hardware.SensorEvent;
import android.hardware.SensorEventListener;
import android.hardware.SensorManager;
import android.os.Bundle;
import android.view.View;
import android.view.animation.AlphaAnimation;
import android.view.animation.Animation;
import android.view.animation.RotateAnimation;
import android.widget.Button;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

import com.google.firebase.auth.FirebaseAuth;
import com.google.firebase.auth.FirebaseUser;
import com.google.firebase.firestore.FirebaseFirestore;
import com.google.firebase.firestore.Source;
import com.google.firebase.messaging.FirebaseMessaging;

public class MainActivity2 extends AppCompatActivity implements SensorEventListener {

    private static final String PREFS_NAME = "AppUsagePrefs";
    private static final String KEY_USAGE_COUNT = "usageCount";
    private static final String KEY_INFO_VISIBLE = "infoVisible";

    private FirebaseAuth auth;
    private FirebaseUser user;
    private FirebaseFirestore db;
    private TextView textNombre, textUser;
    private Button button;

    private ImageView btnExpand;
    private LinearLayout containerUserInfo;

    private SensorManager sensorManager;
    private Sensor accelerometer;
    private float xAccel, yAccel, zAccel;
    private float lastX, lastY, lastZ;
    private long lastTime;

    @SuppressLint("MissingInflatedId")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main2);
        FirebaseMessaging.getInstance().subscribeToTopic("forum_alert");

        db = FirebaseFirestore.getInstance();
        auth = FirebaseAuth.getInstance();
        user = auth.getCurrentUser();

        if (user == null) {
            startActivity(new Intent(getApplicationContext(), Login.class));
            finish();
            return;
        }

        SharedPreferences prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
        int usageCount = prefs.getInt(KEY_USAGE_COUNT, 0) + 1;
        prefs.edit().putInt(KEY_USAGE_COUNT, usageCount).apply();

        textNombre = findViewById(R.id.textnombre);
        textNombre.setText("N°: " + usageCount);

        textUser = findViewById(R.id.userdetails);
        textUser.setText(user.getEmail() != null ? user.getEmail() : user.getUid());

        // ✅ Vérifie l’état du compte en direct
        db.collection("users").document(user.getUid())
                .get(Source.SERVER)
                .addOnSuccessListener(doc -> {
                    if (doc.exists()) {
                        Boolean isActive = doc.getBoolean("isActive");
                        if (isActive == null || !isActive) {
                            Toast.makeText(this, "Ce compte est désactivé.", Toast.LENGTH_LONG).show();
                            logoutAndRedirect();
                        } else {
                            db.collection("users").document(user.getUid())
                                    .update("isOnline", true, "isActive", true);
                        }
                    }
                });

        // ✅ Écoute en temps réel (désactivation distante)
        db.collection("users").document(user.getUid())
                .addSnapshotListener((snapshot, error) -> {
                    if (snapshot != null && snapshot.exists()) {
                        Boolean active = snapshot.getBoolean("isActive");
                        if (active != null && !active) {
                            Toast.makeText(this, "Votre session a été fermée", Toast.LENGTH_LONG).show();
                            logoutAndRedirect();
                        }
                    }
                });

        button = findViewById(R.id.button);
        button.setOnClickListener(v -> startActivity(new Intent(MainActivity2.this, ApproActivity.class)));

        btnExpand = findViewById(R.id.btnExpandInfo);
        containerUserInfo = findViewById(R.id.containerUserInfo);

        boolean wasVisible = prefs.getBoolean(KEY_INFO_VISIBLE, false);
        containerUserInfo.setVisibility(wasVisible ? View.VISIBLE : View.GONE);
        rotateIcon(wasVisible);
        btnExpand.setOnClickListener(v -> toggleUserInfo());

        sensorManager = (SensorManager) getSystemService(SENSOR_SERVICE);
        accelerometer = sensorManager.getDefaultSensor(Sensor.TYPE_ACCELEROMETER);
    }

    private void toggleUserInfo() {
        boolean isVisible = containerUserInfo.getVisibility() == View.VISIBLE;
        if (isVisible) {
            fadeOut(containerUserInfo);
            containerUserInfo.setVisibility(View.GONE);
            rotateIcon(false);
        } else {
            containerUserInfo.setVisibility(View.VISIBLE);
            fadeIn(containerUserInfo);
            rotateIcon(true);
        }
        getSharedPreferences(PREFS_NAME, MODE_PRIVATE).edit().putBoolean(KEY_INFO_VISIBLE, !isVisible).apply();
    }

    private void rotateIcon(boolean expanded) {
        float from = expanded ? 0 : 180;
        float to = expanded ? 180 : 0;
        RotateAnimation rotate = new RotateAnimation(from, to, Animation.RELATIVE_TO_SELF, 0.5f, Animation.RELATIVE_TO_SELF, 0.5f);
        rotate.setDuration(200);
        rotate.setFillAfter(true);
        btnExpand.startAnimation(rotate);
    }

    private void fadeIn(View v) {
        Animation fade = new AlphaAnimation(0f, 1f);
        fade.setDuration(200);
        v.startAnimation(fade);
    }

    private void fadeOut(View v) {
        Animation fade = new AlphaAnimation(1f, 0f);
        fade.setDuration(200);
        v.startAnimation(fade);
    }

    private void logoutAndRedirect() {
        if (user != null) {
            db.collection("users").document(user.getUid())
                    .update("isOnline", false, "isActive", false)
                    .addOnCompleteListener(task -> {
                        FirebaseAuth.getInstance().signOut();
                        getSharedPreferences("APP_PREFS", MODE_PRIVATE).edit().remove("LOGGED_UID").apply();
                        Intent intent = new Intent(MainActivity2.this, Login.class);
                        intent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
                        startActivity(intent);
                        finish();
                    });
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (user != null) {
            db.collection("users").document(user.getUid()).get(Source.SERVER)
                    .addOnSuccessListener(doc -> {
                        if (doc.exists()) {
                            Boolean isActive = doc.getBoolean("isActive");
                            if (isActive != null && !isActive) logoutAndRedirect();
                        }
                    });
        }

        if (accelerometer != null && sensorManager != null)
            sensorManager.registerListener(this, accelerometer, SensorManager.SENSOR_DELAY_UI);
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (sensorManager != null) sensorManager.unregisterListener(this);
    }

    @Override
    public void onSensorChanged(SensorEvent event) {
        if (event.sensor.getType() != Sensor.TYPE_ACCELEROMETER) return;
        long currentTime = System.currentTimeMillis();
        if ((currentTime - lastTime) > 100) {
            long diffTime = currentTime - lastTime;
            lastTime = currentTime;
            xAccel = event.values[0];
            yAccel = event.values[1];
            zAccel = event.values[2];
            float shake = Math.abs(xAccel + yAccel + zAccel - lastX - lastY - lastZ) / diffTime * 10000;
            if (shake > 2200) openNewActivity();
            lastX = xAccel; lastY = yAccel; lastZ = zAccel;
        }
    }

    @Override
    public void onAccuracyChanged(Sensor sensor, int accuracy) {}

    private void openNewActivity() {
        startActivity(new Intent(MainActivity2.this, MainActivity8.class));
    }
}
