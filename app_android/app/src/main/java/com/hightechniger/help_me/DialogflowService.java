package com.hightechniger.help_me;

import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.io.IOException;

import okhttp3.Call;
import okhttp3.Callback;
import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;

public class DialogflowService {
    private static final String PROJECT_ID = "helpme-bot-123456";
    private static final String SESSION_ID = "session123";
    private static final String LANGUAGE_CODE = "fr";
    private static final String ACCESS_TOKEN = "https://oauth2.googleapis.com/token";
    private static final String API_URL = "https://dialogflow.googleapis.com/v2/projects/" + PROJECT_ID + "/agent/sessions/" + SESSION_ID + ":detectIntent";

    private final OkHttpClient client = new OkHttpClient();

    public void sendMessage(String message, DialogflowCallback callback) {
        JsonObject textInput = new JsonObject();
        textInput.addProperty("text", message);
        textInput.addProperty("languageCode", LANGUAGE_CODE);

        JsonObject queryInput = new JsonObject();
        queryInput.add("text", textInput);

        JsonObject requestBody = new JsonObject();
        requestBody.add("queryInput", queryInput);

        RequestBody body = RequestBody.create(requestBody.toString(), MediaType.get("application/json"));
        Request request = new Request.Builder()
                .url(API_URL)
                .addHeader("Authorization", "Bearer " + ACCESS_TOKEN)
                .addHeader("Content-Type", "application/json")
                .post(body)
                .build();

        client.newCall(request).enqueue(new Callback() {
            @Override
            public void onFailure(Call call, IOException e) {
                callback.onError(e.getMessage());
            }

            @Override
            public void onResponse(Call call, Response response) throws IOException {
                if (response.isSuccessful()) {
                    String responseData = response.body().string();
                    JsonObject jsonResponse = JsonParser.parseString(responseData).getAsJsonObject();
                    String reply = jsonResponse.getAsJsonObject("queryResult").get("fulfillmentText").getAsString();
                    callback.onSuccess(reply);
                } else {
                    callback.onError("Erreur API : " + response.code());
                }
            }
        });
    }

    public interface DialogflowCallback {
        void onSuccess(String response);
        void onError(String error);
    }
}
