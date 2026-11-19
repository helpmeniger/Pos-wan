package com.hightechniger.help_me;

import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.text.TextUtils;
import android.widget.Button;
import android.widget.EditText;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

import com.android.volley.Request;
import com.android.volley.RequestQueue;
import com.android.volley.toolbox.JsonObjectRequest;
import com.android.volley.toolbox.Volley;
import com.google.firebase.auth.FirebaseAuth;
import com.google.firebase.database.DatabaseReference;
import com.google.firebase.database.FirebaseDatabase;
import com.google.firebase.messaging.FirebaseMessaging;
import com.hightechniger.help_me.push.FCMTokenManager;

import org.json.JSONObject;

import java.util.HashMap;
import java.util.Map;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

public class LoginActivity2 extends AppCompatActivity {

    private static final String PREFS = "HelpMePrefs";
    private static final String KEY_NAME = "saved_user_name";

    // 🔐 Sécurité pour API Render (remplace par tes valeurs!)
    private static final String API_URL = BuildConfig.HM_LOGIN_URL;
    private static final String API_KEY = BuildConfig.HM_API_KEY;
    private static final String HMAC_SECRET = BuildConfig.HM_HMAC_SECRET;

    private EditText etName;
    private Button btnStart;
    private FirebaseAuth auth;
    private DatabaseReference db;

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);

        SharedPreferences sp = getSharedPreferences(PREFS, MODE_PRIVATE);
        String savedName = sp.getString(KEY_NAME, null);
        auth = FirebaseAuth.getInstance();
        db = FirebaseDatabase.getInstance().getReference();

        // ✅ Auto-connexion si nom déjà enregistré
        if (savedName != null && !savedName.trim().isEmpty()) {
            ensureLoginAndGo(savedName);
            return;
        }

        setContentView(R.layout.activity_login2);
        etName = findViewById(R.id.etName);
        btnStart = findViewById(R.id.btnStart);

        btnStart.setOnClickListener(v -> {
            String name = etName.getText().toString().trim();
            if (TextUtils.isEmpty(name)) {
                Toast.makeText(this, "Entrez votre nom", Toast.LENGTH_SHORT).show();
                return;
            }

            getSharedPreferences(PREFS, MODE_PRIVATE)
                    .edit()
                    .putString(KEY_NAME, name)
                    .apply();

            ensureLoginAndGo(name);
        });
    }

    private void ensureLoginAndGo(String name) {
        if (auth.getCurrentUser() == null) {
            auth.signInAnonymously()
                    .addOnSuccessListener(res -> registerUser(name))
                    .addOnFailureListener(e -> {
                        Toast.makeText(this, e.getMessage(), Toast.LENGTH_LONG).show();
                        setContentView(R.layout.activity_login2);
                    });
        } else {
            registerUser(name);
        }
    }

    private void registerUser(String name) {
        String uid = auth.getCurrentUser().getUid();

        DatabaseReference userRef = db.child("users").child(uid);
        userRef.child("uid").setValue(uid);
        userRef.child("name").setValue(name);
        userRef.child("online").onDisconnect().setValue(false);
        userRef.child("online").setValue(true);
        userRef.child("status").setValue("available"); // ✅ joueur prêt

        // Score par défaut
        userRef.child("lastScore").get().addOnSuccessListener(snap -> {
            if (!snap.exists() || String.valueOf(snap.getValue()).trim().isEmpty()) {
                userRef.child("lastScore").setValue("0-0");
            }
        });

        // ✅ Mise à jour Token FCM
        FCMTokenManager.refreshAndSave();

        // ✅ S'abonner au topic match
        FirebaseMessaging.getInstance().subscribeToTopic("match_alert");

        // ✅ Prévenir ton serveur Render que joueur est en ligne
        sendAvailableToServer(name);

        // ✅ Aller au lobby
        Intent i = new Intent(this, LobbyActivity.class);
        i.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_NEW_TASK);
        startActivity(i);
        finish();
    }

    // ✅ Appel API Render pour push "joueur dispo"
    private void sendAvailableToServer(String name) {
        try {
            JSONObject body = new JSONObject();
            body.put("name", name);

            String bodyStr = body.toString();
            String signature = hmacSha256(bodyStr, HMAC_SECRET);

            RequestQueue queue = Volley.newRequestQueue(this);

            JsonObjectRequest req = new JsonObjectRequest(
                    Request.Method.POST,
                    API_URL,
                    body,
                    response -> Toast.makeText(this, "Connecté ✅", Toast.LENGTH_SHORT).show(),
                    error -> Toast.makeText(this, "Serveur indisponible ❌", Toast.LENGTH_SHORT).show()
            ) {
                @Override
                public Map<String, String> getHeaders() {
                    HashMap<String, String> headers = new HashMap<>();
                    headers.put("x-api-key", API_KEY);
                    headers.put("x-signature", signature);
                    headers.put("Content-Type", "application/json");
                    return headers;
                }
            };

            queue.add(req);

        } catch (Exception e) {
            e.printStackTrace();
        }
    }

    // ✅ Fonction HMAC SHA-256 (sécurité)
    private String hmacSha256(String data, String secret) {
        try {
            SecretKeySpec key = new SecretKeySpec(secret.getBytes(), "HmacSHA256");
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(key);
            byte[] hash = mac.doFinal(data.getBytes());

            StringBuilder sb = new StringBuilder();
            for (byte b : hash) sb.append(String.format("%02x", b));
            return sb.toString();
        } catch (Exception e) {
            return "";
        }
    }
}
