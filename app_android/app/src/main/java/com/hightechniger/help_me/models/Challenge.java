package com.hightechniger.help_me.models;

public class Challenge {
    public String fromUid;
    public String fromName;
    public long createdAt;

    public Challenge() {}
    public Challenge(String fromUid, String fromName, long createdAt) {
        this.fromUid = fromUid;
        this.fromName = fromName;
        this.createdAt = createdAt;
    }
}
