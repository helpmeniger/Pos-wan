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
        for (String code : new String[]{"2019", "2018", "2001", "2002"})
            org.junit.Assert.assertTrue(code, AnimLessonActivity.exists(a, code));
        Robolectric.buildActivity(AnimLessonActivity.class, new Intent().putExtra(AnimLessonActivity.EXTRA_CODE, "2019")).setup().pause().destroy();
    }
}
