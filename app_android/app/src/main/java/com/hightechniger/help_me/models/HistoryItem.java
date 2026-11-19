package com.hightechniger.help_me.models;

public class HistoryItem {
    public String matchId;
    public String opponentUid;
    public String opponentName;
    public String score;         // ex: "4-2"
    public Long timestamp;       // date exacte du match
    public Long durationMs;      // durée totale du match (en ms)

    public HistoryItem() {} // requis par Firebase
}
