package com.hightechniger.help_me.models;

import java.util.Map;

public class User {
    public String uid;
    public String name;
    public Boolean online;           // true = connecté
    public String status;            // "available" | "busy" | "offline"
    public String lastScore;         // ex: "5-3"
    public Boolean champion;         // ⭐ badge si >= 15 adversaires uniques
    public Map<String, Boolean> opponents; // ex: { "uid1": true, "uid2": true }
    public Long wins;                // total de victoires
    public Long uniqueOpponents;     // total d’adversaires battus différents
    public String fcmToken;          // 🔔 Token FCM pour les notifications push
    public Long gamesPlayed;
    public Long losses;
    // ✅ Constructeur vide requis par Firebase
    public User() {}

    // ✅ Constructeur complet (utile pour les créations manuelles)
    public User(String uid, String name, Boolean online, String status, String lastScore,
                Boolean champion, Map<String, Boolean> opponents, Long wins,
                Long uniqueOpponents, String fcmToken) {
        this.uid = uid;
        this.name = name;
        this.online = online;
        this.status = status;
        this.lastScore = lastScore;
        this.champion = champion;
        this.opponents = opponents;
        this.wins = wins;
        this.uniqueOpponents = uniqueOpponents;
        this.fcmToken = fcmToken;
    }

    // ✅ Méthodes utiles (optionnelles, aident Android Studio à reconnaître leur usage)
    public boolean isOnline() {
        return Boolean.TRUE.equals(online);
    }

    public boolean isAvailable() {
        return isOnline() && "available".equals(status);
    }

    public String getDisplayName() {
        return name != null ? name : "Joueur inconnu";
    }
}
