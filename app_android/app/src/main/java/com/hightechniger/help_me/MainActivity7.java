package com.hightechniger.help_me;

import android.content.SharedPreferences;
import android.os.Bundle;
import android.text.TextUtils;
import android.view.LayoutInflater;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.appcompat.app.AlertDialog;
import androidx.appcompat.app.AppCompatActivity;

import java.util.ArrayList;
import java.util.List;

public class MainActivity7 extends AppCompatActivity {

    private static final String PREFS_NAME = "NotePrefs";
    private static final String KEY_NOTE_COUNT = "NoteCount";
    private LinearLayout notesContainer;
    private List<Note> noteList;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main7);

        notesContainer = findViewById(R.id.notesContainer);
        Button saveButton = findViewById(R.id.saveButton);

        noteList = new ArrayList<>();

        // Load saved notes and display them
        loadNotesFromPreferences();
        displayNotes();

        // Save button listener
        saveButton.setOnClickListener(v -> saveNote());
    }

    /**
     * Display notes from the noteList dynamically.
     */
    private void displayNotes() {
        notesContainer.removeAllViews(); // Clear existing views to avoid duplication
        for (Note note : noteList) {
            createNoteView(note);
        }
    }

    /**
     * Load notes from SharedPreferences into the noteList.
     */
    private void loadNotesFromPreferences() {
        SharedPreferences sharedPreferences = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
        int noteCount = sharedPreferences.getInt(KEY_NOTE_COUNT, 0);

        for (int i = 0; i < noteCount; i++) {
            String title = sharedPreferences.getString("note_title_" + i, "");
            String content = sharedPreferences.getString("note_content_" + i, "");

            if (!TextUtils.isEmpty(title) && !TextUtils.isEmpty(content)) {
                Note note = new Note();
                note.setTitle(title);
                note.setContent(content);
                noteList.add(note);
            }
        }
    }

    /**
     * Save a new note entered by the user.
     */
    private void saveNote() {
        EditText titleEditText = findViewById(R.id.titleEditText);
        EditText contentEditText = findViewById(R.id.contentEditText);

        String title = titleEditText.getText().toString().trim();
        String content = contentEditText.getText().toString().trim();

        // Validate inputs
        if (TextUtils.isEmpty(title) || TextUtils.isEmpty(content)) {
            showErrorDialog("Veuillez remplir tous les champs.");
            return;
        }

        // Add the new note to the list
        Note note = new Note();
        note.setTitle(title);
        note.setContent(content);

        noteList.add(note);
        saveNotesToPreferences(); // Save to preferences

        // Update UI and clear inputs
        createNoteView(note);
        clearInputFields();
    }

    /**
     * Clear the input fields for title and content.
     */
    private void clearInputFields() {
        EditText titleEditText = findViewById(R.id.titleEditText);
        EditText contentEditText = findViewById(R.id.contentEditText);

        titleEditText.getText().clear();
        contentEditText.getText().clear();
    }

    /**
     * Create and display a note view dynamically.
     */
    private void createNoteView(final Note note) {
        View noteView = LayoutInflater.from(this).inflate(R.layout.note_item, notesContainer, false);
        TextView titleTextView = noteView.findViewById(R.id.titleTextView);
        TextView contentTextView = noteView.findViewById(R.id.contentTextView);

        titleTextView.setText(note.getTitle());
        contentTextView.setText(note.getContent());

        // Add long-click listener for deletion
        noteView.setOnLongClickListener(v -> {
            showDeleteDialog(note);
            return true;
        });

        // Add the note view to the container
        notesContainer.addView(noteView);
    }

    /**
     * Show a confirmation dialog for deleting a note.
     */
    private void showDeleteDialog(final Note note) {
        new AlertDialog.Builder(this)
                .setTitle("Supprimer cette note")
                .setMessage("Êtes-vous sûr de vouloir supprimer cette note ?")
                .setPositiveButton("Supprimer", (dialog, which) -> deleteNoteAndRefresh(note))
                .setNegativeButton("Annuler", null)
                .show();
    }

    /**
     * Delete a note from the list and refresh the UI.
     */
    private void deleteNoteAndRefresh(Note note) {
        noteList.remove(note);
        saveNotesToPreferences(); // Save changes
        displayNotes(); // Refresh UI
    }

    /**
     * Save all notes to SharedPreferences.
     */
    private void saveNotesToPreferences() {
        SharedPreferences sharedPreferences = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
        SharedPreferences.Editor editor = sharedPreferences.edit();

        editor.putInt(KEY_NOTE_COUNT, noteList.size());
        for (int i = 0; i < noteList.size(); i++) {
            Note note = noteList.get(i);
            editor.putString("note_title_" + i, note.getTitle());
            editor.putString("note_content_" + i, note.getContent());
        }
        editor.apply();
    }

    /**
     * Show an error dialog with a custom message.
     */
    private void showErrorDialog(String message) {
        new AlertDialog.Builder(this)
                .setTitle("Erreur")
                .setMessage(message)
                .setPositiveButton("OK", null)
                .show();
    }
}
