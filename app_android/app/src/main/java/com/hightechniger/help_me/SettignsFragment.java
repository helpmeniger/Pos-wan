package com.hightechniger.help_me;

import android.app.Activity;
import android.content.Intent;
import android.os.Bundle;

import androidx.annotation.NonNull;
import androidx.fragment.app.Fragment;

import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.Button;
import android.widget.ImageButton;


public class SettignsFragment extends Fragment {

   Activity context;
    @Override
    public View onCreateView(@NonNull LayoutInflater inflater, ViewGroup container,
                             Bundle savedInstanceState) {
        context = getActivity();
        View root = inflater.inflate(R.layout.fragment_settigns, container,false);
        return root;



    }
    public void onStart(){
        super.onStart();
        ImageButton btn = (ImageButton)  context.findViewById(R.id.button2);
        btn.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                Intent intent = new Intent(context,MainActivity7.class);
                startActivity(intent);
            }
        });
    }

}