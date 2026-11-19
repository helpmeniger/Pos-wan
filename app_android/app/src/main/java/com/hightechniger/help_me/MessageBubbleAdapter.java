package com.hightechniger.help_me;

import android.content.Context;
import android.graphics.Color;
import android.view.Gravity;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.LinearLayout;
import android.widget.TextView;

import androidx.cardview.widget.CardView;
import androidx.core.content.ContextCompat;
import androidx.recyclerview.widget.RecyclerView;

import java.util.ArrayList;

public class MessageBubbleAdapter extends RecyclerView.Adapter<MessageBubbleAdapter.ViewHolder> {

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
        View view = LayoutInflater.from(context).inflate(R.layout.item_message_bubble, parent, false);
        return new ViewHolder(view);
    }

    @Override
    public void onBindViewHolder(ViewHolder holder, int position) {
        MessageModel msg = messages.get(position);
        holder.messageText.setText(msg.message);
        holder.messageInfo.setText("par " + msg.userEmail + " - " + msg.date);

        boolean isMine = msg.userEmail != null && msg.userEmail.equals(currentUserEmail);

        // Alignement et couleur
        if (isMine) {
            holder.container.setGravity(Gravity.END);
            holder.messageCard.setCardBackgroundColor(ContextCompat.getColor(context, R.color.white));
            holder.messageText.setTextColor(Color.BLACK);
            holder.messageInfo.setTextColor(Color.BLACK);
        } else {
            holder.container.setGravity(Gravity.START);
            holder.messageCard.setCardBackgroundColor(Color.WHITE);
            holder.messageText.setTextColor(Color.BLACK);
            holder.messageInfo.setTextColor(Color.DKGRAY);
        }

        // Si question → surligner légèrement
        if (msg.isQuestion) {
            holder.messageCard.setCardBackgroundColor(Color.parseColor("#FFF176")); // jaune clair
        }
    }

    @Override
    public int getItemCount() {
        return messages.size();
    }

    public static class ViewHolder extends RecyclerView.ViewHolder {
        TextView messageText, messageInfo;
        CardView messageCard;
        LinearLayout container;

        public ViewHolder(View itemView) {
            super(itemView);
            messageText = itemView.findViewById(R.id.messageText);
            messageInfo = itemView.findViewById(R.id.messageInfo);
            messageCard = itemView.findViewById(R.id.messageCard);
            container = (LinearLayout) itemView;
        }
    }
}
