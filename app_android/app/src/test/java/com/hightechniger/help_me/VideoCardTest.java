package com.hightechniger.help_me;

import android.app.Activity;
import android.content.Intent;
import org.junit.Test;
import org.junit.runner.RunWith;
import org.robolectric.Robolectric;
import org.robolectric.RobolectricTestRunner;
import org.robolectric.annotation.Config;
import org.robolectric.annotation.GraphicsMode;

@RunWith(RobolectricTestRunner.class)
@GraphicsMode(GraphicsMode.Mode.NATIVE)
@Config(sdk = 35, qualifiers = "w411dp-h891dp-xxhdpi")
public class VideoCardTest {
    @Test public void card() throws Exception {
        Activity a = Robolectric.buildActivity(LessonReaderActivity.class, new Intent().putExtra("lesson", "ordinateur-05")).setup().get();
        ScreensTest.shot(a, "v_lecon_video", 0);
        Activity b = Robolectric.buildActivity(VideoPlayerActivity.class, new Intent()
            .putExtra("res", VideoPlayerActivity.videoFor(a, "2019")).putExtra("title", "Allumer l’ordinateur")).setup().get();
    }
}
