package com.hightechniger.help_me;

import android.annotation.SuppressLint;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.net.ConnectivityManager;
import android.net.NetworkInfo;
import android.os.Bundle;
import android.text.SpannableString;
import android.text.style.BackgroundColorSpan;
import android.text.style.StyleSpan;
import android.view.View;
import android.widget.EditText;
import android.widget.ImageButton;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.appcompat.app.AppCompatActivity;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

import com.google.firebase.auth.FirebaseAuth;
import com.google.firebase.auth.FirebaseUser;
import com.google.firebase.database.DataSnapshot;
import com.google.firebase.database.DatabaseError;
import com.google.firebase.database.DatabaseReference;
import com.google.firebase.database.FirebaseDatabase;
import com.google.firebase.database.ValueEventListener;
import com.google.firebase.messaging.FirebaseMessaging;
import com.hightechniger.help_me.push.HttpHelper;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.HashMap;
import java.util.Locale;
import java.util.Map;

public class ForumActivity extends AppCompatActivity {

    // 🔥 Visible pour éviter d'envoyer une notif à l'utilisateur déjà sur la page
    public static boolean isForumVisible = false;

    // 🔥 Compteur global des messages non lus pour MainActivity
    public static int unreadCount = 0;

    private static final String PREFS_NAME = "ForumPrefs";
    private static final String KEY_MESSAGES = "savedMessages";
    private static final String KEY_LAST_READ = "last_read_timestamp";

    private static final long THREE_DAYS_IN_MILLIS = 3 * 24 * 60 * 60 * 1000L;

    private static final SimpleDateFormat DATE_FORMAT =
            new SimpleDateFormat("dd MMM yyyy", Locale.getDefault());

    private EditText messageInput;
    private ImageButton sendButton;
    private RecyclerView messageRecyclerView;
    private SwipeRefreshLayout swipeRefreshLayout;
    private TextView connectionStatus;
    private ProgressBar progressBar;

    private FirebaseAuth mAuth;
    private FirebaseUser user;
    private DatabaseReference mDatabase;
    private String userEmail;

    private ArrayList<MessageModel> messageList;
    private MessageBubbleAdapter messageAdapter;
    private BroadcastReceiver networkReceiver;
    private ValueEventListener messagesListener;

    private int messageCount = 0;

    @SuppressLint("MissingInflatedId")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_forum);
        FirebaseMessaging.getInstance().subscribeToTopic("forum_alert");

        mAuth = FirebaseAuth.getInstance();
        user = mAuth.getCurrentUser();

        if (user == null) {
            startActivity(new Intent(getApplicationContext(), Login.class));
            finish();
            return;
        }

        userEmail = user.getEmail();
        mDatabase = FirebaseDatabase.getInstance().getReference("messages");

        messageInput = findViewById(R.id.messageInput);
        sendButton = findViewById(R.id.sendButton);
        messageRecyclerView = findViewById(R.id.messageRecyclerView);
        progressBar = findViewById(R.id.progressBar);
        swipeRefreshLayout = findViewById(R.id.swipeRefreshLayout);
        connectionStatus = findViewById(R.id.connectionStatus);

        messageList = new ArrayList<>();
        messageAdapter = new MessageBubbleAdapter(this, messageList, userEmail);
        messageRecyclerView.setLayoutManager(new LinearLayoutManager(this));
        messageRecyclerView.setAdapter(messageAdapter);

        checkConnectionStatus();
        loadLocalMessages();
        fetchMessages();
        removeOldMessages();

        sendButton.setOnClickListener(v -> {
            if (!isConnected()) {
                Toast.makeText(this, "Pas de connexion Internet", Toast.LENGTH_SHORT).show();
                return;
            }

            String message = messageInput.getText().toString().trim();
            if (!message.isEmpty()) {
                sendMessage(message);
                messageInput.setText("");
            }
        });

        swipeRefreshLayout.setOnRefreshListener(this::fetchMessages);

        networkReceiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context context, Intent intent) {
                checkConnectionStatus();
            }
        };
        registerReceiver(networkReceiver, new IntentFilter(ConnectivityManager.CONNECTIVITY_ACTION));
    }

    @Override
    protected void onResume() {
        super.onResume();
        isForumVisible = true;

        // 🔥 On marque tous les messages comme lus quand on ouvre le forum
        SharedPreferences prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
        prefs.edit().putLong(KEY_LAST_READ, System.currentTimeMillis()).apply();

        unreadCount = 0;

        // 🔥 Informer MainActivity qu’il doit cacher son badge
        sendBroadcast(new Intent("UPDATE_FORUM_BADGE"));
    }

    @Override
    protected void onPause() {
        super.onPause();
        isForumVisible = false;
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        if (networkReceiver != null) unregisterReceiver(networkReceiver);
        if (messagesListener != null) mDatabase.removeEventListener(messagesListener);
    }

    private boolean isConnected() {
        ConnectivityManager cm = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
        NetworkInfo active = cm.getActiveNetworkInfo();
        return active != null && active.isConnectedOrConnecting();
    }

    private void checkConnectionStatus() {
        boolean online = isConnected();
        connectionStatus.setText(online ? "En ligne..." : "Hors ligne");
        connectionStatus.setTextColor(Color.WHITE);

        if (!online) Toast.makeText(this, "Vous êtes hors ligne", Toast.LENGTH_SHORT).show();
    }

    private void sendMessage(String message) {
        progressBar.setVisibility(View.VISIBLE);
        long timestamp = System.currentTimeMillis();
        String dateSent = DATE_FORMAT.format(new Date(timestamp));

        Map<String, Object> messageMap = new HashMap<>();
        messageMap.put("message", message);
        messageMap.put("userEmail", userEmail);
        messageMap.put("date", dateSent);
        messageMap.put("timestamp", timestamp);

        mDatabase.push().setValue(messageMap)
                .addOnSuccessListener(aVoid -> {

                    // 🔥 Notif FCM pour les autres utilisateurs
                    if (!ForumActivity.isForumVisible) {
                        String json = "{\"type\":\"FORUM_HELP\"}";

                        HttpHelper.postJsonSigned(
                                "https://helpme-fcm-relay.onrender.com/notify-forum",
                                BuildConfig.HM_FORUM_RELAY_KEY,
                                BuildConfig.HM_HMAC_SECRET,
                                json,
                                (ok, res) -> {}
                        );
                    }

                    Toast.makeText(this, "Message envoyé", Toast.LENGTH_SHORT).show();
                    progressBar.setVisibility(View.GONE);
                })
                .addOnFailureListener(e -> {
                    Toast.makeText(this, "Erreur d'envoi", Toast.LENGTH_SHORT).show();
                    progressBar.setVisibility(View.GONE);
                });
    }

    private void fetchMessages() {
        progressBar.setVisibility(View.VISIBLE);

        messagesListener = new ValueEventListener() {
            @Override
            public void onDataChange(DataSnapshot snapshot) {

                messageList.clear();
                messageCount = 0;

                SharedPreferences prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
                long lastRead = prefs.getLong(KEY_LAST_READ, 0);

                unreadCount = 0;

                for (DataSnapshot data : snapshot.getChildren()) {

                    String msg = data.child("message").getValue(String.class);
                    String email = data.child("userEmail").getValue(String.class);
                    String date = data.child("date").getValue(String.class);
                    Long ts = data.child("timestamp").getValue(Long.class);

                    if (msg != null && email != null && date != null) {

                        messageCount++;

                        boolean isQuestion = msg.trim().endsWith("?");
                        String prefix = isQuestion ? "Question" : "Réponse";

                        SpannableString styledText =
                                new SpannableString(prefix + " " + messageCount + ": " + msg);

                        styledText.setSpan(new StyleSpan(android.graphics.Typeface.BOLD),
                                0, prefix.length(), SpannableString.SPAN_EXCLUSIVE_EXCLUSIVE);

                        if (isQuestion) {
                            styledText.setSpan(new BackgroundColorSpan(Color.YELLOW),
                                    0, styledText.length(), SpannableString.SPAN_EXCLUSIVE_EXCLUSIVE);
                        }

                        MessageModel model = new MessageModel();
                        model.message = styledText.toString();
                        model.userEmail = email;
                        model.date = date;
                        model.timestamp = ts != null ? ts : System.currentTimeMillis();
                        model.isQuestion = isQuestion;

                        messageList.add(model);

                        // 🔥 Message non-lu :
                        if (ts != null && ts > lastRead && !email.equals(userEmail)) {
                            unreadCount++;
                        }
                    }
                }

                saveMessagesLocally();
                messageAdapter.notifyDataSetChanged();
                messageRecyclerView.scrollToPosition(messageList.size() - 1);

                // 🔥 Informer MainActivity que le badge doit changer
                sendBroadcast(new Intent("UPDATE_FORUM_BADGE"));

                progressBar.setVisibility(View.GONE);
                swipeRefreshLayout.setRefreshing(false);
            }

            @Override
            public void onCancelled(DatabaseError error) {
                progressBar.setVisibility(View.GONE);
                swipeRefreshLayout.setRefreshing(false);
                Toast.makeText(ForumActivity.this, "Erreur : " + error.getMessage(), Toast.LENGTH_SHORT).show();
            }
        };

        mDatabase.orderByChild("timestamp").addValueEventListener(messagesListener);
    }

    private void removeOldMessages() {
        long now = System.currentTimeMillis();

        mDatabase.addListenerForSingleValueEvent(new ValueEventListener() {
            @Override
            public void onDataChange(DataSnapshot snapshot) {

                for (DataSnapshot snap : snapshot.getChildren()) {
                    Long ts = snap.child("timestamp").getValue(Long.class);

                    if (ts != null && now - ts > THREE_DAYS_IN_MILLIS) {
                        snap.getRef().removeValue();
                    }
                }
            }

            @Override public void onCancelled(DatabaseError error) {}
        });
    }

    private void saveMessagesLocally() {
        SharedPreferences prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE);
        SharedPreferences.Editor editor = prefs.edit();
        JSONArray arr = new JSONArray();

        for (MessageModel m : messageList) {
            JSONObject obj = new JSONObject();
            try {
                obj.put("message", m.message);
                obj.put("userEmail", m.userEmail);
                obj.put("date", m.date);
                obj.put("timestamp", m.timestamp);
                obj.put("isQuestion", m.isQuestion);
                arr.put(obj);
            } catch (JSONException ignored) {}
        }

        editor.putString(KEY_MESSAGES, arr.toString());
        editor.apply();
    }

    private void loadLocalMessages() {
        SharedPreferences prefs = getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE);
        String data = prefs.getString(KEY_MESSAGES, "[]");

        try {
            JSONArray arr = new JSONArray(data);

            for (int i = 0; i < arr.length(); i++) {

                JSONObject obj = arr.getJSONObject(i);

                MessageModel m = new MessageModel();
                m.message = obj.getString("message");
                m.userEmail = obj.getString("userEmail");
                m.date = obj.getString("date");
                m.timestamp = obj.getLong("timestamp");
                m.isQuestion = obj.getBoolean("isQuestion");

                messageList.add(m);
            }

        } catch (JSONException ignored) {}

        messageAdapter.notifyDataSetChanged();
    }
}
