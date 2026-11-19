package com.hightechniger.help_me.models;

import java.util.List;

public class Match {
    public String matchId;
    public String p1Uid;
    public String p2Uid;
    public String p1Name;
    public String p2Name;
    public int p1Score;
    public int p2Score;
    public int pickerTurn;
    public String state;      // "waiting", "question_sent", "finished"
    public int currentQuestionIndex;
    public Long answerDeadline;
    public String answererUid;

    // ✅ AJOUT ici (pour corriger ta couleur rouge)
    public Long startTime;        // Date/heure exacte du début du match (IMPORTANT)

    public List<Question> questions;
    public List<Boolean> used;    // ex: [false,false,true...]
    public String endReason = "";
    public boolean cheatDetected = false;
    public String endedByUid = "";
    public String endedByName = "";
    public String cheatNote = "";
    public String opponentAlert = "";
    public Match() {} // Obligatoire Firebase
}
