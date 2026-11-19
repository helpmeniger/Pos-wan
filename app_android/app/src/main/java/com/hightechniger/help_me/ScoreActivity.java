package com.hightechniger.help_me;

import android.content.Intent;
import android.os.Bundle;
import android.widget.TextView;
import androidx.appcompat.app.AppCompatActivity;
import com.google.android.material.button.MaterialButton;

public class ScoreActivity extends AppCompatActivity {

    private TextView tvResult;
    private MaterialButton btnRestart;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_score);

        tvResult = findViewById(R.id.tvResult);
        btnRestart = findViewById(R.id.btnRestart);

        int score = getIntent().getIntExtra("SCORE", 0);
        int total = getIntent().getIntExtra("TOTAL", 0);

        tvResult.setText("🎯 Ton score : " + score + "/" + total);

        btnRestart.setOnClickListener(v -> {
            startActivity(new Intent(this, QuizActivity.class));
            finish();
        });
    }
}
