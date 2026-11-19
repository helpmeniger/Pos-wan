package com.hightechniger.help_me.push;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;

import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

public class HttpHelper {

    // ✅ Fonction HMAC SHA-256
    private static String sign(String secret, String body) throws Exception {
        Mac sha256 = Mac.getInstance("HmacSHA256");
        sha256.init(new SecretKeySpec(secret.getBytes(), "HmacSHA256"));
        byte[] hash = sha256.doFinal(body.getBytes());
        StringBuilder sb = new StringBuilder();
        for (byte b : hash) sb.append(String.format("%02x", b));
        return sb.toString();
    }

    // ✅ Envoi POST JSON signé avec API KEY + HMAC + timeout
    public static void postJsonSigned(String urlStr, String apiKey, String hmacSecret, String json, Callback cb) {

        new Thread(() -> {
            boolean success = false;
            String response = "";

            try {
                URL url = new URL(urlStr);
                HttpURLConnection conn = (HttpURLConnection) url.openConnection();

                conn.setConnectTimeout(6000); // 6 sec max
                conn.setReadTimeout(6000);
                conn.setRequestMethod("POST");

                conn.setRequestProperty("Content-Type", "application/json");

                // ✅ IMPORTANT : respecter la casse des headers côté Node/Render
                conn.setRequestProperty("x-api-key", apiKey);
                conn.setRequestProperty("x-signature", sign(hmacSecret, json));

                conn.setDoOutput(true);

                try (OutputStream os = conn.getOutputStream()) {
                    os.write(json.getBytes());
                    os.flush();
                }

                int code = conn.getResponseCode();
                success = (code >= 200 && code < 300);

                // ✅ Lire réponse serveur (utile debug)
                BufferedReader br = new BufferedReader(
                        new InputStreamReader(
                                success ? conn.getInputStream() : conn.getErrorStream()
                        )
                );
                StringBuilder sb = new StringBuilder();
                String line;
                while ((line = br.readLine()) != null) sb.append(line);
                response = sb.toString();

            } catch (Exception e) {
                response = e.toString();
            }

            if (cb != null) cb.onDone(success, response);
        }).start();
    }

    // ✅ Callback pour retour
    public interface Callback {
        void onDone(boolean ok, String res);
    }
}
