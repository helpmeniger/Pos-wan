package com.hightechniger.help_me;

import android.content.Context;
import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.recyclerview.widget.RecyclerView;

import java.util.ArrayList;
import java.util.Locale;

/**
 * Messages du forum en bulles : les miens à droite (bleu), ceux des autres à gauche avec leurs initiales.
 * Seul le prénom tiré de l'adresse est affiché (l'adresse e-mail complète reste privée).
 */
public class MessageBubbleAdapter extends RecyclerView.Adapter<MessageBubbleAdapter.ViewHolder> {

    private static final int[] AVATAR_COLORS = {0xFF326FBF, 0xFF0F8A5F, 0xFF6A4FC2, 0xFFC43E1C, 0xFFD9822B, 0xFF1D6F42, 0xFF37474F};

    private final Context context;
    private final ArrayList<MessageModel> messages;
    private final String currentUserEmail;

    public MessageBubbleAdapter(Context context, ArrayList<MessageModel> messages, String currentUserEmail) {
        this.context = context;
        this.messages = messages;
        this.currentUserEmail = currentUserEmail;
    }

    @Override
    public ViewHolder onCreateViewHolder(ViewGroup parent, int viewType) {
        return new ViewHolder(LayoutInflater.from(context).inflate(R.layout.item_message_bubble, parent, false));
    }

    /** « aichatou.hamidou@gmail.com » -> « Aichatou ». */
    static String displayName(String email) {
        if (email == null || email.isEmpty()) return "Apprenant";
        String n = email.contains("@") ? email.substring(0, email.indexOf('@')) : email;
        n = n.split("[._\\-\\d]+")[0];
        if (n.isEmpty()) return "Apprenant";
        return Character.toUpperCase(n.charAt(0)) + n.substring(1).toLowerCase(Locale.FRANCE);
    }

    @Override
    public void onBindViewHolder(ViewHolder h, int position) {
        MessageModel msg = messages.get(position);
        boolean mine = msg.userEmail != null && msg.userEmail.equals(currentUserEmail);
        // anciens messages enregistrés avec un préfixe « Question 3: » / « Réponse 4: »
        String text = msg.message == null ? "" : msg.message.replaceFirst("^(Question|Réponse) \\d+ ?: ?", "");
        h.messageText.setText(text);
        h.messageInfo.setText(msg.date);
        String name = displayName(msg.userEmail);
        h.name.setText(mine ? "Vous" : name);
        h.tag.setVisibility(msg.isQuestion ? View.VISIBLE : View.GONE);

        LinearLayout.LayoutParams lp = (LinearLayout.LayoutParams) h.container.getLayoutParams();
        if (mine) {
            h.container.setGravity(android.view.Gravity.END);
            h.container.setPadding(dp(52), dp(5), 0, dp(5));
            h.avatar.setVisibility(View.GONE);
            h.bubble.setBackgroundResource(R.drawable.chat_bubble_user);
            h.messageText.setTextColor(Color.WHITE);
            h.messageInfo.setTextColor(0xCCFFFFFF);
            h.name.setTextColor(0xE6FFFFFF);
        } else {
            h.container.setGravity(android.view.Gravity.START);
            h.container.setPadding(0, dp(5), dp(40), dp(5));
            h.avatar.setVisibility(View.VISIBLE);
            h.avatar.setText(name.substring(0, Math.min(2, name.length())).toUpperCase(Locale.FRANCE));
            GradientDrawable g = new GradientDrawable();
            g.setShape(GradientDrawable.OVAL);
            g.setColor(AVATAR_COLORS[Math.abs(name.hashCode()) % AVATAR_COLORS.length]);
            h.avatar.setBackground(g);
            h.bubble.setBackgroundResource(R.drawable.chat_bubble_ai);
            h.messageText.setTextColor(0xFF1C2430);
            h.messageInfo.setTextColor(0xFF7A8596);
            h.name.setTextColor(0xFF326FBF);
        }
        h.container.setLayoutParams(lp);
    }

    private int dp(int v) { return Math.round(v * context.getResources().getDisplayMetrics().density); }

    @Override
    public int getItemCount() { return messages.size(); }

    public static class ViewHolder extends RecyclerView.ViewHolder {
        final TextView messageText, messageInfo, name, tag, avatar;
        final LinearLayout container, bubble;

        public ViewHolder(View itemView) {
            super(itemView);
            messageText = itemView.findViewById(R.id.messageText);
            messageInfo = itemView.findViewById(R.id.messageInfo);
            name = itemView.findViewById(R.id.tvName);
            tag = itemView.findViewById(R.id.tvTag);
            avatar = itemView.findViewById(R.id.tvAvatar);
            bubble = itemView.findViewById(R.id.bubble);
            container = (LinearLayout) itemView;
        }
    }
}
