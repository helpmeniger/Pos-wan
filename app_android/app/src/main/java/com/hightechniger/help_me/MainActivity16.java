package com.hightechniger.help_me;

import android.app.DatePickerDialog;
import android.os.Bundle;
import android.text.TextUtils;
import android.widget.ArrayAdapter;
import android.widget.AutoCompleteTextView;
import android.widget.Button;
import android.widget.EditText;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;

import com.google.firebase.database.DatabaseReference;
import com.google.firebase.database.FirebaseDatabase;

import java.text.ParseException;
import java.text.SimpleDateFormat;
import java.util.Calendar;
import java.util.Date;
import java.util.HashMap;
import java.util.Locale;
import java.util.Map;

public class MainActivity16 extends AppCompatActivity {

    private EditText edtName, edtSurname, edtBirthDate, edtBirthPlace, edtDescription, edttelephone;
    private AutoCompleteTextView edtregion;
    private Button btnSubmit;
    private DatabaseReference databaseReference;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main16);

        databaseReference = FirebaseDatabase.getInstance().getReference("APPRENANT");

        // Initialize Views
        edtName = findViewById(R.id.edtName);
        edtSurname = findViewById(R.id.edtSurname);
        edtBirthDate = findViewById(R.id.edtBirthDate);
        edtBirthPlace = findViewById(R.id.edtBirthPlace);
        edtDescription = findViewById(R.id.edtDescription);
        edtregion = findViewById(R.id.edtregion1); // AutoCompleteTextView
        edttelephone = findViewById(R.id.edttelephone1);
        btnSubmit = findViewById(R.id.btnSubmit);

        // Initialize DatePicker for Birth Date
        edtBirthDate.setOnClickListener(v -> {
            Calendar calendar = Calendar.getInstance();
            int year = calendar.get(Calendar.YEAR);
            int month = calendar.get(Calendar.MONTH);
            int day = calendar.get(Calendar.DAY_OF_MONTH);

            DatePickerDialog datePickerDialog = new DatePickerDialog(
                    MainActivity16.this,
                    (view, year1, monthOfYear, dayOfMonth) -> {
                        String formattedDate = String.format("%02d/%02d/%d", dayOfMonth, monthOfYear + 1, year1);
                        edtBirthDate.setText(formattedDate);
                    },
                    year, month, day
            );

            datePickerDialog.show();
        });

        edtBirthDate.setOnFocusChangeListener((v, hasFocus) -> {
            if (!hasFocus) {
                String inputDate = edtBirthDate.getText().toString();
                SimpleDateFormat inputFormat = new SimpleDateFormat("dd/MM/yyyy", Locale.getDefault());
                SimpleDateFormat outputFormat = new SimpleDateFormat("dd/MM/yyyy", Locale.getDefault());

                try {
                    Date date = inputFormat.parse(inputDate);
                    if (date != null) {
                        String formattedDate = outputFormat.format(date);
                        edtBirthDate.setText(formattedDate);
                    }
                } catch (ParseException e) {
                    edtBirthDate.setText("");
                    Toast.makeText(getApplicationContext(),
                            "Date invalide. Veuillez entrer une date au format dd/MM/yyyy",
                            Toast.LENGTH_SHORT).show();
                }
            }
        });

        // Set up the dropdown list for regions
        String[] regions = {"Niamey", "Agadez", "Tahoua", "Maradi", "Zinder", "Diffa", "Tillabéri"};
        ArrayAdapter<String> adapter = new ArrayAdapter<>(
                this,
                android.R.layout.simple_dropdown_item_1line,
                regions
        );
        edtregion.setAdapter(adapter);

        // Show dropdown when field is clicked
        edtregion.setOnClickListener(v -> edtregion.showDropDown());

        // Set Click Listener on Submit Button
        btnSubmit.setOnClickListener(v -> submitData());
    }

    private void submitData() {
        // Retrieve input values
        String name = edtName.getText().toString().trim();
        String surname = edtSurname.getText().toString().trim();
        String birthDate = edtBirthDate.getText().toString().trim();
        String birthPlace = edtBirthPlace.getText().toString().trim();
        String description = edtDescription.getText().toString().trim();
        String region = edtregion.getText().toString().trim();
        String phone = edttelephone.getText().toString().trim();

        // Validate input
        if (TextUtils.isEmpty(name) || TextUtils.isEmpty(surname) ||
                TextUtils.isEmpty(birthDate) || TextUtils.isEmpty(birthPlace) ||
                TextUtils.isEmpty(description) || TextUtils.isEmpty(region) || TextUtils.isEmpty(phone)) {
            Toast.makeText(this, "Veuillez remplir tous les champs, s'il vous plaît.", Toast.LENGTH_SHORT).show();
            return;
        }

        // Create a map to store user data
        Map<String, String> userData = new HashMap<>();
        userData.put("Nom", name);
        userData.put("Prenom", surname);
        userData.put("Date de naissance", birthDate);
        userData.put("Lieu de naissance", birthPlace);
        userData.put("Description", description);
        userData.put("Région", region);
        userData.put("Téléphone", phone);

        // Push data to Firebase
        databaseReference.push().setValue(userData).addOnCompleteListener(task -> {
            if (task.isSuccessful()) {
                Toast.makeText(MainActivity16.this, "Votre demande de certification a été envoyée avec succès.", Toast.LENGTH_SHORT).show();
                clearFields();
            } else {
                Toast.makeText(MainActivity16.this,
                        "Échec de la connexion. Veuillez vérifier votre connexion Internet. Si le problème persiste, contactez Help-me.",
                        Toast.LENGTH_SHORT).show();
            }
        });
    }

    private void clearFields() {
        edtName.setText("");
        edtSurname.setText("");
        edtBirthDate.setText("");
        edtBirthPlace.setText("");
        edtDescription.setText("");
        edttelephone.setText("");
        edtregion.setText("");
    }
}
