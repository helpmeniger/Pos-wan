package com.hightechniger.help_me;

public class Item {
    private String title;
    private String link;
    private String description;

    public Item() {
        // Required for Firebase
    }

    public Item(String title, String link, String description) {
        this.title = title;
        this.link = link;
        this.description = description;
    }

    public String getTitle() {
        return title;
    }

    public String getLink() {
        return link;
    }

    public String getDescription() {
        return description;
    }
}

