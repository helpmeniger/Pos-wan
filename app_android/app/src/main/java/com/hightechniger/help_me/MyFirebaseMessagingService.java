package com.hightechniger.help_me;

import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.os.Build;

import androidx.core.app.NotificationCompat;

import com.google.firebase.messaging.FirebaseMessaging;
import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;
import com.hightechniger.help_me.push.FCMTokenManager;

public class MyFirebaseMessagingService extends FirebaseMessagingService {

    private static final String CH_MATCH = "helpme_match";
    private static final String CH_FORUM = "helpme_forum";

    @Override
    public void onCreate() {
        super.onCreate();

        FirebaseMessaging.getInstance().subscribeToTopic("match_alert");
        FirebaseMessaging.getInstance().subscribeToTopic("forum_alert");
    }

    @Override
    public void onMessageReceived(RemoteMessage remoteMessage) {

        if (remoteMessage.getData() == null) return;

        String kind = remoteMessage.getData().get("kind");   // "match_available" ou "forum_message"
        String playerName = remoteMessage.getData().get("playerName");

        String title = "Notification";
        String body = "Vous avez un message";

        // Si backend inclut un champ notification
        if (remoteMessage.getNotification() != null) {
            if (remoteMessage.getNotification().getTitle() != null)
                title = remoteMessage.getNotification().getTitle();
            if (remoteMessage.getNotification().getBody() != null)
                body = remoteMessage.getNotification().getBody();
        }

        // ----------------------------------------------------
        // 🔥 1 — NOTIFICATION FORUM
        // ----------------------------------------------------
        if ("forum_message".equals(kind)) {

            body = "Un utilisateur cherche de l’aide dans le Forum 🔥";

            Intent intent = new Intent(this, ForumActivity.class);
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);

            showNotification(CH_FORUM, "Forum Aide", title, body, intent);
            return;
        }

        // ----------------------------------------------------
        // 🔥 2 — NOTIFICATION MATCH
        // ----------------------------------------------------
        if ("match_available".equals(kind)) {

            // Si lobby ouvert → aucune notification
            if (LobbyActivity.isLobbyVisible) return;

            // ✔️ CAS 1 : Avec playerName → matchmaking normal
            if (playerName != null && !playerName.trim().isEmpty()) {
                body = playerName + " est en ligne 🔥";
            }
            // ✔️ CAS 2 : Sans playerName → défi depuis classement
            else {
                body = "Un joueur veut vous affronter 🔥";
            }

            Intent intent = new Intent(this, RouterActivity.class);
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);

            showNotification(CH_MATCH, "Matchmaking joueurs", title, body, intent);
        }
    }

    private void showNotification(String channelId, String channelName,
                                  String title, String body, Intent intent) {

        NotificationManager nm =
                (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);

        // Android 8+
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel ch = new NotificationChannel(
                    channelId,
                    channelName,
                    NotificationManager.IMPORTANCE_HIGH
            );
            nm.createNotificationChannel(ch);
        }

        PendingIntent pi = PendingIntent.getActivity(
                this,
                (int) System.currentTimeMillis(),
                intent,
                Build.VERSION.SDK_INT >= Build.VERSION_CODES.S
                        ? PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_MUTABLE
                        : PendingIntent.FLAG_UPDATE_CURRENT
        );

        NotificationCompat.Builder b = new NotificationCompat.Builder(this, channelId)
                .setSmallIcon(R.drawable.logobook)
                .setContentTitle(title)
                .setContentText(body)
                .setAutoCancel(true)
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setContentIntent(pi);

        nm.notify((int) System.currentTimeMillis(), b.build());
    }

    @Override
    public void onNewToken(String token) {
        FCMTokenManager.refreshAndSave();
    }
}
