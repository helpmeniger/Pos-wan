package com.hightechniger.help_me;

import android.app.AlarmManager;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.Build;

import androidx.core.app.NotificationCompat;

import java.util.Random;

public class NotificationScheduler {

    public static void scheduleNotifications(Context context) {
        AlarmManager alarmManager = (AlarmManager) context.getSystemService(Context.ALARM_SERVICE);
        Intent intent = new Intent(context, NotificationReceiver.class);

        // PendingIntent qui se déclenche même si l'app n'est pas ouverte
        PendingIntent pendingIntent = PendingIntent.getBroadcast(
                context,
                0,
                intent,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
        );

        // Définir l'intervalle de répétition (2 jours ici)
        long interval = AlarmManager.INTERVAL_DAY * 2; // Intervalle de 2 jours
        long triggerTime = System.currentTimeMillis() + interval;

        // Configurer l'Alarme
        if (alarmManager != null) {
            alarmManager.setInexactRepeating(
                    AlarmManager.RTC_WAKEUP, // Réveille l'appareil si nécessaire
                    triggerTime,
                    interval,
                    pendingIntent
            );
        }
    }

    public static class NotificationReceiver extends BroadcastReceiver {
        @Override
        public void onReceive(Context context, Intent intent) {
            showNotification(context);
        }

        private void showNotification(Context context) {
            // Liste des messages de notification
            String[] messages = {
                    "Continuez à apprendre avec Help-me et développez vos compétences numériques !",
                    "Chaque jour est une opportunité de devenir meilleur. Apprenez avec Help-me !",
                    "Boostez vos compétences numériques aujourd'hui avec Help-me !",
                    "N'abandonnez pas ! Les compétences numériques sont l'avenir, apprenez avec Help-me !",
                    "Un pas à la fois : continuez à progresser avec Help-me !",
                    "Parlez à vos proches de Help-me et aidez-les à progresser !",
                    "Changer le monde en devenant compétent dans votre domaine d'activité avec Help-me !",
                    "Help-me, c'est une affaire de tous !",
                    "On rencontre les outils bureautiques dans tous les domaines d'activité. Apprenez et maîtrisez-les !",
                    "Changer le Niger avec vos compétences, apprenez avec Help-me !",
                    "L'avenir vous appartient, prenez-le en main avec Help-me !"
            };

            // Choisir un message aléatoire
            Random random = new Random();
            String randomMessage = messages[random.nextInt(messages.length)];

            // Configurer le canal de notification
            String channelId = "helpme_channel";
            String channelName = "Help-me Notifications";

            NotificationManager notificationManager = (NotificationManager) context.getSystemService(Context.NOTIFICATION_SERVICE);

            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                NotificationChannel channel = new NotificationChannel(
                        channelId,
                        channelName,
                        NotificationManager.IMPORTANCE_HIGH
                );
                notificationManager.createNotificationChannel(channel);
            }

            // Intention pour ouvrir `MainActivity` lorsqu'on clique sur la notification
            Intent notificationIntent = new Intent(context, Login.class);
            PendingIntent pendingIntent = PendingIntent.getActivity(
                    context,
                    0,
                    notificationIntent,
                    PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
            );

            // Construire la notification
            NotificationCompat.Builder builder = new NotificationCompat.Builder(context, channelId)
                    .setSmallIcon(R.drawable.logobook) // Remplacez par une icône appropriée
                    .setContentTitle("Help-me vous encourage !")
                    .setContentText(randomMessage)
                    .setPriority(NotificationCompat.PRIORITY_HIGH)
                    .setAutoCancel(true) // La notification disparaît lorsqu'on clique dessus
                    .setContentIntent(pendingIntent);

            // Afficher la notification
            notificationManager.notify(new Random().nextInt(1000), builder.build());
        }
    }
}
