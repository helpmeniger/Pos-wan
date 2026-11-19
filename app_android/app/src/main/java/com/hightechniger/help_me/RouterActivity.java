package com.hightechniger.help_me;

import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Bundle;

import androidx.appcompat.app.AppCompatActivity;

public class RouterActivity extends AppCompatActivity {
    private static final String PREFS = "HelpMePrefs";
    private static final String KEY_NAME = "saved_user_name";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        SharedPreferences sp = getSharedPreferences(PREFS, MODE_PRIVATE);
        String savedName = sp.getString(KEY_NAME, null);

        if (savedName != null && !savedName.trim().isEmpty()) {
            startActivity(new Intent(this, LobbyActivity.class));
        } else {
            startActivity(new Intent(this, LoginActivity2.class));
        }
        finish();
    }
}
