package com.hightechniger.help_me.push;

import android.util.Log;

import com.google.firebase.auth.FirebaseAuth;
import com.google.firebase.database.FirebaseDatabase;
import com.google.firebase.messaging.FirebaseMessaging;

public class FCMTokenManager {

    private static final String TAG = "FCM_TOKEN";

    public static void refreshAndSave() {

        FirebaseMessaging.getInstance().getToken()
                .addOnSuccessListener(token -> {

                    String uid = FirebaseAuth.getInstance().getCurrentUser() != null
                            ? FirebaseAuth.getInstance().getCurrentUser().getUid()
                            : null;

                    if (uid == null || token == null || token.trim().isEmpty()) {
                        Log.w(TAG, "Token OR UID null — skipping");
                        return;
                    }

                    // 🔥 Sauvegarde token dans Firebase
                    FirebaseDatabase.getInstance().getReference()
                            .child("users")
                            .child(uid)
                            .child("fcmToken")
                            .setValue(token)
                            .addOnSuccessListener(v -> Log.d(TAG, "Token saved OK"))
                            .addOnFailureListener(e -> Log.e(TAG, "Token save error ❌", e));

                    // 🔥 Abonnement au topic MATCH
                    FirebaseMessaging.getInstance()
                            .subscribeToTopic("match_alert")
                            .addOnSuccessListener(a -> Log.d(TAG, "Subscribed to match_alert"))
                            .addOnFailureListener(e -> Log.e(TAG, "Subscribe match_alert ❌", e));

                    // 🔥 Abonnement au topic FORUM
                    FirebaseMessaging.getInstance()
                            .subscribeToTopic("forum_alert")
                            .addOnSuccessListener(a -> Log.d(TAG, "Subscribed to forum_alert"))
                            .addOnFailureListener(e -> Log.e(TAG, "Subscribe forum_alert ❌", e));

                })
                .addOnFailureListener(e -> Log.e(TAG, "Token fetch fail ❌", e));
    }
}
